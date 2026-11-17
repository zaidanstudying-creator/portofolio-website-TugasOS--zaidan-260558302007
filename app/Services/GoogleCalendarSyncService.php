<?php

namespace App\Services;

use App\Jobs\SyncGoogleCalendar;
use App\Models\IntegrationConnection;
use App\Models\SyncEvent;
use App\Models\Task;
use App\Models\TaskSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class GoogleCalendarSyncService
{
    public function __construct(private GoogleAccessTokenService $tokens, private GoogleApiClient $googleApi) {}

    /**
     * @return list<array{id: string, summary: string, primary: bool, access_role: string}>
     */
    public function calendars(User $user): array
    {
        $connection = $user->integrations()
            ->where('provider', 'google')
            ->where('status', 'connected')
            ->firstOrFail();
        $requiredScope = 'https://www.googleapis.com/auth/calendar.calendarlist.readonly';

        if (! in_array($requiredScope, $connection->scopes ?? [], true)) {
            throw new RuntimeException('Reconnect Google to grant calendar-list access.');
        }

        $accessToken = $this->tokens->for($connection);
        $pageToken = null;
        $calendars = [];

        do {
            $query = ['maxResults' => 250, 'fields' => 'items(id,summary,primary,accessRole),nextPageToken'];

            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }

            $response = $this->googleApi->request($accessToken)
                ->get('https://www.googleapis.com/calendar/v3/users/me/calendarList', $query)
                ->throw()
                ->json();

            foreach ($response['items'] ?? [] as $calendar) {
                if (! is_array($calendar)
                    || ! is_string($calendar['id'] ?? null)
                    || ! is_string($calendar['summary'] ?? null)
                    || ! in_array($calendar['accessRole'] ?? null, ['owner', 'writer'], true)) {
                    continue;
                }

                $calendars[] = [
                    'id' => $calendar['id'],
                    'summary' => $calendar['summary'],
                    'primary' => (bool) ($calendar['primary'] ?? false),
                    'access_role' => $calendar['accessRole'],
                ];
            }

            $pageToken = is_string($response['nextPageToken'] ?? null) ? $response['nextPageToken'] : null;
        } while ($pageToken !== null);

        return $calendars;
    }

    public function sync(User $user): int
    {
        $connection = $this->connectedGoogleConnection($user);
        $accessToken = $this->tokens->for($connection);
        $calendarId = $this->calendarId($connection);

        $synchronized = 0;
        $failedCount = 0;
        $schedules = $user->taskSchedules()
            ->whereHas('task', fn ($query) => $query->whereNull('archived_at'))
            ->with('task')
            ->get();
        $allScheduleIds = array_values(array_map('intval', $schedules->modelKeys()));
        $importedScheduleIds = $connection->mappings()
            ->where('resource_type', 'task_schedule')
            ->where('status', 'imported')
            ->pluck('internal_id')
            ->flip();

        foreach ($schedules as $schedule) {
            if ($importedScheduleIds->has((string) $schedule->getKey())) {
                continue;
            }

            $succeeded = $this->syncSchedule($connection, $accessToken, $calendarId, $schedule);
            $synchronized += (int) $succeeded;
            $failedCount += (int) ! $succeeded;
        }

        $tasksWithDeadlines = $user->tasks()->whereNull('archived_at')->whereNotNull('due_at')->get();

        foreach ($tasksWithDeadlines as $task) {
            $succeeded = $this->syncDeadline($connection, $accessToken, $calendarId, $task);
            $synchronized += (int) $succeeded;
            $failedCount += (int) ! $succeeded;
        }

        $deadlineTaskIds = array_values(array_map('intval', $tasksWithDeadlines->modelKeys()));
        $failedCount += $this->deleteStaleMappings($connection, $accessToken, 'task_schedule', $allScheduleIds);
        $failedCount += $this->deleteStaleMappings($connection, $accessToken, 'task_deadline', $deadlineTaskIds);

        $connection->update([
            'last_synced_at' => now(),
            'last_error' => $failedCount > 0 ? "{$failedCount} item(s) failed to synchronize." : null,
        ]);

        return $synchronized;
    }

    public function queueSync(User $user): SyncEvent
    {
        return $this->enqueueSync($user, false)
            ?? throw new RuntimeException('Google connection is not active.');
    }

    public function queueSyncIfConnected(User $user): ?SyncEvent
    {
        return $this->enqueueSync($user, true);
    }

    private function enqueueSync(User $user, bool $allowMissingConnection): ?SyncEvent
    {
        return DB::transaction(function () use ($user, $allowMissingConnection): ?SyncEvent {
            $connection = $user->integrations()
                ->where('provider', 'google')
                ->lockForUpdate()
                ->first();

            if (! $connection) {
                if ($allowMissingConnection) {
                    return null;
                }

                throw new RuntimeException('Google connection is not active.');
            }

            if ($connection->status !== 'connected') {
                if ($allowMissingConnection) {
                    return null;
                }

                throw new RuntimeException('Google connection is not active.');
            }

            $pendingEvent = $connection->syncEvents()
                ->where('provider', 'google_calendar')
                ->where('action', 'calendar_sync')
                ->whereIn('status', ['queued', 'running'])
                ->latest()
                ->first();

            if ($pendingEvent && $pendingEvent->updated_at?->greaterThan(now()->subMinutes(15))) {
                return $pendingEvent;
            }

            $pendingEvent?->update([
                'status' => 'failed',
                'message' => 'Sinkronisasi sebelumnya berhenti sebelum selesai. Silakan coba lagi.',
            ]);

            $event = $connection->syncEvents()->create([
                'provider' => 'google_calendar',
                'direction' => 'outbound',
                'action' => 'calendar_sync',
                'status' => 'queued',
                'message' => 'Sinkronisasi masuk antrean.',
                'context' => [],
            ]);

            SyncGoogleCalendar::dispatch($connection->id, $event->id)->afterCommit();

            return $event;
        });
    }

    /**
     * @return list<array{id: string, summary: string, start: string, end: string, all_day: bool, html_link: ?string}>
     */
    public function importableEvents(User $user): array
    {
        $connection = $this->connectedGoogleConnection($user);
        $accessToken = $this->tokens->for($connection);
        $calendarId = $this->calendarId($connection);
        $windowStart = now();
        $windowEnd = $windowStart->addDays(30);
        $importedEventIds = $connection->mappings()
            ->where('resource_type', 'task_schedule')
            ->where('external_container_id', $calendarId)
            ->pluck('external_id')
            ->all();
        $events = [];
        $pageToken = null;

        do {
            $query = [
                'maxResults' => 250,
                'orderBy' => 'startTime',
                'singleEvents' => true,
                'timeMin' => $windowStart->toRfc3339String(),
                'timeMax' => $windowEnd->toRfc3339String(),
                'fields' => 'items(id,status,summary,start,end,htmlLink,recurrence,recurringEventId,extendedProperties),nextPageToken',
            ];

            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }

            $response = $this->googleApi->request($accessToken)
                ->get('https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId).'/events', $query)
                ->throw()
                ->json();

            foreach ($response['items'] ?? [] as $event) {
                if (! is_array($event)
                    || ! is_string($event['id'] ?? null)
                    || in_array($event['id'], $importedEventIds, true)
                    || ! in_array($event['status'] ?? null, ['confirmed', 'tentative'], true)
                    || ! is_string($event['summary'] ?? null)
                    || trim($event['summary']) === ''
                    || ! is_array($event['start'] ?? null)
                    || ! is_array($event['end'] ?? null)
                    || isset($event['recurrence'])
                    || isset($event['recurringEventId'])
                    || isset($event['extendedProperties']['private']['tugasosScheduleId'])
                    || isset($event['extendedProperties']['private']['tugasosTaskDeadlineId'])) {
                    continue;
                }

                $start = $event['start']['dateTime'] ?? $event['start']['date'] ?? null;
                $end = $event['end']['dateTime'] ?? $event['end']['date'] ?? null;

                if (! is_string($start) || ! is_string($end)) {
                    continue;
                }

                $events[] = [
                    'id' => $event['id'],
                    'summary' => mb_substr(trim($event['summary']), 0, 255),
                    'start' => $start,
                    'end' => $end,
                    'all_day' => isset($event['start']['date']),
                    'html_link' => is_string($event['htmlLink'] ?? null) ? $event['htmlLink'] : null,
                ];
            }

            $pageToken = is_string($response['nextPageToken'] ?? null) ? $response['nextPageToken'] : null;
        } while ($pageToken !== null);

        return $events;
    }

    public function importEvent(User $user, string $eventId): Task
    {
        $connection = $this->connectedGoogleConnection($user);
        $accessToken = $this->tokens->for($connection);
        $calendarId = $this->calendarId($connection);
        $eventUrl = 'https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId);
        $event = $this->googleApi->request($accessToken)
            ->get($eventUrl)
            ->throw()
            ->json();

        if (($event['id'] ?? null) !== $eventId
            || ! in_array($event['status'] ?? null, ['confirmed', 'tentative'], true)
            || ! is_string($event['summary'] ?? null)
            || trim($event['summary']) === ''
            || ! is_array($event['start'] ?? null)
            || ! is_array($event['end'] ?? null)
            || isset($event['recurrence'])
            || isset($event['recurringEventId'])
            || isset($event['extendedProperties']['private']['tugasosScheduleId'])
            || isset($event['extendedProperties']['private']['tugasosTaskDeadlineId'])) {
            throw new RuntimeException('This Google Calendar event cannot be imported.');
        }

        $startValue = $event['start']['dateTime'] ?? $event['start']['date'] ?? null;
        $endValue = $event['end']['dateTime'] ?? $event['end']['date'] ?? null;

        if (! is_string($startValue) || ! is_string($endValue)) {
            throw new RuntimeException('Google Calendar did not return a valid event time.');
        }

        $start = CarbonImmutable::parse($startValue);
        $end = CarbonImmutable::parse($endValue);
        $isAllDay = isset($event['start']['date']);

        if ($end->lessThanOrEqualTo($start)) {
            throw new RuntimeException('Google Calendar event must end after it starts.');
        }

        return DB::transaction(function () use ($user, $connection, $event, $eventId, $calendarId, $start, $end, $isAllDay): Task {
            $lockedConnection = $user->integrations()->whereKey($connection->getKey())->lockForUpdate()->firstOrFail();
            $alreadyImported = $lockedConnection->mappings()
                ->where('resource_type', 'task_schedule')
                ->where('external_container_id', $calendarId)
                ->where('external_id', $eventId)
                ->exists();

            if ($alreadyImported) {
                throw new RuntimeException('This Google Calendar event is already linked to TUGAS!OS.');
            }

            $summary = mb_substr(trim($event['summary']), 0, 255);
            $description = trim(strip_tags((string) ($event['description'] ?? '')));
            $htmlLink = $event['htmlLink'] ?? null;
            $taskDescription = 'Diimpor dari Google Calendar.';

            if ($description !== '') {
                $taskDescription .= "\n\n".mb_substr($description, 0, 4500);
            }

            $task = $user->tasks()->create([
                'title' => $summary,
                'description' => $taskDescription,
                'type' => 'flexible',
                'status' => 'todo',
                'priority' => 'medium',
            ]);
            $task->statusEvents()->create([
                'user_id' => $user->id,
                'from_status' => null,
                'to_status' => 'todo',
                'note' => 'Imported from Google Calendar',
            ]);
            $schedule = $task->schedules()->create([
                'user_id' => $user->id,
                'starts_at' => $start,
                'ends_at' => $end,
                'is_all_day' => $isAllDay,
            ]);

            if (is_string($htmlLink)
                && in_array(parse_url($htmlLink, PHP_URL_SCHEME), ['http', 'https'], true)) {
                $task->links()->create([
                    'user_id' => $user->id,
                    'label' => 'Google Calendar event',
                    'url' => $htmlLink,
                ]);
            }

            $lockedConnection->mappings()->create([
                'resource_type' => 'task_schedule',
                'internal_id' => (string) $schedule->id,
                'external_id' => $eventId,
                'external_container_id' => $calendarId,
                'sync_hash' => hash('sha256', json_encode($event, JSON_THROW_ON_ERROR)),
                'last_synced_at' => now(),
                'status' => 'imported',
            ]);
            $lockedConnection->syncEvents()->create([
                'provider' => 'google_calendar',
                'direction' => 'inbound',
                'action' => 'import_event',
                'status' => 'succeeded',
                'context' => ['task_id' => $task->id, 'schedule_id' => $schedule->id],
            ]);

            return $task;
        });
    }

    private function connectedGoogleConnection(User $user): IntegrationConnection
    {
        return $user->integrations()
            ->where('provider', 'google')
            ->where('status', 'connected')
            ->firstOrFail();
    }

    private function calendarId(IntegrationConnection $connection): string
    {
        $calendarId = $connection->settings['calendar_id'] ?? 'primary';

        if (! is_string($calendarId) || trim($calendarId) === '') {
            throw new RuntimeException('The selected Google Calendar is invalid. Choose a calendar again.');
        }

        return $calendarId;
    }

    private function syncSchedule(IntegrationConnection $connection, string $accessToken, string $calendarId, TaskSchedule $schedule): bool
    {
        $endsAt = $schedule->ends_at;
        $description = 'Jadwal pengerjaan dari TUGAS!OS. Deadline resmi: '.($schedule->task->due_at ? $this->calendarDateTime($schedule->task->due_at) : 'belum ditentukan').'.';

        if ($schedule->is_all_day && $endsAt->toDateString() <= $schedule->starts_at->toDateString()) {
            $endsAt = $schedule->starts_at->addDay();
        }

        return $this->upsertEvent(
            $connection,
            $accessToken,
            $calendarId,
            'task_schedule',
            (string) $schedule->id,
            'tugasos-schedule-'.$schedule->id,
            [
                'summary' => $schedule->label ?: $schedule->task->title,
                'description' => $description,
                'start' => $schedule->is_all_day
                    ? ['date' => $schedule->starts_at->toDateString()]
                    : ['dateTime' => $this->calendarDateTime($schedule->starts_at), 'timeZone' => config('services.google.calendar_timezone')],
                'end' => $schedule->is_all_day
                    ? ['date' => $endsAt->toDateString()]
                    : ['dateTime' => $this->calendarDateTime($endsAt), 'timeZone' => config('services.google.calendar_timezone')],
                'extendedProperties' => ['private' => ['tugasosScheduleId' => (string) $schedule->id]],
            ],
            'create_event',
            'update_event',
            ['task_id' => $schedule->task_id, 'schedule_id' => $schedule->id],
        );
    }

    private function syncDeadline(IntegrationConnection $connection, string $accessToken, string $calendarId, Task $task): bool
    {
        $dueAt = $task->due_at;

        if ($dueAt === null) {
            return false;
        }

        return $this->upsertEvent(
            $connection,
            $accessToken,
            $calendarId,
            'task_deadline',
            (string) $task->id,
            'tugasos-deadline-'.$task->id,
            [
                'summary' => 'Deadline · '.$task->title,
                'description' => 'Deadline resmi dari TUGAS!OS. Mengubah jadwal pengerjaan tidak mengubah tenggat ini.',
                'start' => ['dateTime' => $this->calendarDateTime($dueAt), 'timeZone' => config('services.google.calendar_timezone')],
                'end' => ['dateTime' => $this->calendarDateTime($dueAt->addMinutes(5)), 'timeZone' => config('services.google.calendar_timezone')],
                'extendedProperties' => ['private' => ['tugasosTaskDeadlineId' => (string) $task->id]],
            ],
            'create_deadline',
            'update_deadline',
            ['task_id' => $task->id],
        );
    }

    private function calendarDateTime(CarbonInterface $dateTime): string
    {
        $timezone = (string) config('services.google.calendar_timezone', 'UTC');

        return CarbonImmutable::parse($dateTime->format('Y-m-d H:i:s.u'), $timezone)->toRfc3339String();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, int>  $context
     */
    private function upsertEvent(
        IntegrationConnection $connection,
        string $accessToken,
        string $calendarId,
        string $resourceType,
        string $internalId,
        string $eventSeed,
        array $payload,
        string $createAction,
        string $updateAction,
        array $context,
    ): bool {
        $mapping = $connection->mappings()
            ->where('resource_type', $resourceType)
            ->where('internal_id', $internalId)
            ->first();
        $eventId = $mapping->external_id ?? substr(hash('sha256', $eventSeed), 0, 32);
        $ownershipProperty = match ($resourceType) {
            'task_schedule' => 'tugasosScheduleId',
            'task_deadline' => 'tugasosTaskDeadlineId',
            default => throw new RuntimeException('Unsupported Google Calendar resource type.'),
        };
        $payload['id'] = $eventId;
        $action = $mapping ? $updateAction : $createAction;
        try {
            if ($mapping && $mapping->external_container_id !== $calendarId) {
                if ($mapping->status === 'imported') {
                    $calendarId = $mapping->external_container_id ?: $calendarId;
                } else {
                    $oldCalendarId = rawurlencode($mapping->external_container_id ?: 'primary');
                    $delete = $this->googleApi->request($accessToken)
                        ->delete("https://www.googleapis.com/calendar/v3/calendars/{$oldCalendarId}/events/".rawurlencode($mapping->external_id));

                    if (! in_array($delete->status(), [404, 410], true)) {
                        $delete->throw();
                    }

                    $mapping->delete();
                    $mapping = null;
                    $action = $createAction;
                }
            }

            $encodedCalendarId = rawurlencode($calendarId);
            $eventUrl = "https://www.googleapis.com/calendar/v3/calendars/{$encodedCalendarId}/events/".rawurlencode($eventId);
            $collectionUrl = "https://www.googleapis.com/calendar/v3/calendars/{$encodedCalendarId}/events";
            $response = $mapping
                ? $this->googleApi->request($accessToken)->patch($eventUrl, $payload)
                : $this->googleApi->request($accessToken)->post($collectionUrl, $payload);

            if ($mapping && in_array($response->status(), [404, 410], true)) {
                $response = $this->googleApi->request($accessToken)
                    ->post($collectionUrl, $payload);
            }

            if ($response->status() === 409) {
                $response = $this->googleApi->request($accessToken)->get($eventUrl);
                $event = $response->throw()->json();

                if (($event['id'] ?? null) !== $eventId
                    || data_get($event, 'extendedProperties.private.'.$ownershipProperty) !== $internalId) {
                    throw new RuntimeException('Google Calendar event ID is already used by an unrelated event. The existing event was not changed.');
                }
            } else {
                $event = $response->throw()->json();
            }

            if (($event['id'] ?? null) !== $eventId) {
                throw new RuntimeException('Google Calendar did not return an event ID.');
            }

            $connection->mappings()->updateOrCreate(
                ['resource_type' => $resourceType, 'internal_id' => $internalId],
                [
                    'external_id' => $event['id'],
                    'external_container_id' => $calendarId,
                    'sync_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                    'last_synced_at' => now(),
                    'status' => $mapping?->status === 'imported' ? 'imported' : 'synced',
                ],
            );
            $connection->syncEvents()->create([
                'provider' => 'google_calendar',
                'direction' => 'outbound',
                'action' => $action,
                'status' => 'succeeded',
                'context' => $context,
            ]);

            return true;
        } catch (Throwable $exception) {
            $connection->syncEvents()->create([
                'provider' => 'google_calendar',
                'direction' => 'outbound',
                'action' => $action,
                'status' => 'failed',
                'message' => $exception instanceof RequestException
                    ? 'Google Calendar returned HTTP '.$exception->response->status().'.'
                    : ($exception instanceof RuntimeException ? $exception->getMessage() : 'Could not reach Google Calendar.'),
                'context' => $context,
            ]);

            return false;
        }
    }

    /**
     * @param  list<int>  $validInternalIds
     */
    private function deleteStaleMappings(IntegrationConnection $connection, string $accessToken, string $resourceType, array $validInternalIds): int
    {
        $staleMappings = $connection->mappings()->where('resource_type', $resourceType);

        if ($validInternalIds !== []) {
            $staleMappings->whereNotIn('internal_id', array_map('strval', $validInternalIds));
        }

        $failedCount = 0;

        foreach ($staleMappings->get() as $mapping) {
            try {
                if ($mapping->status === 'imported') {
                    $mapping->delete();
                    $connection->syncEvents()->create([
                        'provider' => 'google_calendar',
                        'direction' => 'outbound',
                        'action' => 'unlink_imported_event',
                        'status' => 'succeeded',
                        'context' => ['resource_type' => $resourceType, 'internal_id' => $mapping->internal_id],
                    ]);

                    continue;
                }

                $calendarId = rawurlencode($mapping->external_container_id ?: 'primary');
                $eventId = rawurlencode($mapping->external_id);
                $response = $this->googleApi->request($accessToken)
                    ->delete("https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events/{$eventId}");

                if (! in_array($response->status(), [404, 410], true)) {
                    $response->throw();
                }

                $mapping->delete();
                $connection->syncEvents()->create([
                    'provider' => 'google_calendar',
                    'direction' => 'outbound',
                    'action' => 'delete_event',
                    'status' => 'succeeded',
                    'context' => ['resource_type' => $resourceType, 'internal_id' => $mapping->internal_id],
                ]);
            } catch (Throwable) {
                $failedCount++;
                $connection->syncEvents()->create([
                    'provider' => 'google_calendar',
                    'direction' => 'outbound',
                    'action' => 'delete_event',
                    'status' => 'failed',
                    'message' => 'Google Calendar event could not be removed. It will be retried on the next sync.',
                    'context' => ['resource_type' => $resourceType, 'internal_id' => $mapping->internal_id],
                ]);
            }
        }

        return $failedCount;
    }
}
