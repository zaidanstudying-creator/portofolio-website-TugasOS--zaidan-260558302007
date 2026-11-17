<?php

namespace App\Observers;

use App\Models\ExternalResourceMapping;
use App\Models\TaskSchedule;
use App\Services\GoogleCalendarSyncService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class TaskScheduleCalendarSyncObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private GoogleCalendarSyncService $calendarSync) {}

    public function created(TaskSchedule $schedule): void
    {
        if ($this->wasImported($schedule)) {
            return;
        }

        $this->queueSync($schedule);
    }

    public function updated(TaskSchedule $schedule): void
    {
        if ($schedule->wasChanged(['starts_at', 'ends_at', 'is_all_day', 'label'])) {
            $this->queueSync($schedule);
        }
    }

    public function deleted(TaskSchedule $schedule): void
    {
        $this->queueSync($schedule);
    }

    private function wasImported(TaskSchedule $schedule): bool
    {
        return ExternalResourceMapping::query()
            ->where('resource_type', 'task_schedule')
            ->where('internal_id', (string) $schedule->getKey())
            ->where('status', 'imported')
            ->whereHas('connection', fn ($query) => $query
                ->where('user_id', $schedule->user_id)
                ->where('provider', 'google'))
            ->exists();
    }

    private function queueSync(TaskSchedule $schedule): void
    {
        $user = $schedule->user()->first();

        if ($user) {
            $this->calendarSync->queueSyncIfConnected($user);
        }
    }
}
