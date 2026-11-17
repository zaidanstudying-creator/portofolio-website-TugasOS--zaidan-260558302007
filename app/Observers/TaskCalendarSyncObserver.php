<?php

namespace App\Observers;

use App\Models\IntegrationConnection;
use App\Models\Task;
use App\Services\GoogleCalendarSyncService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class TaskCalendarSyncObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private GoogleCalendarSyncService $calendarSync) {}

    public function created(Task $task): void
    {
        if ($this->hasImportedSchedule($task)) {
            return;
        }

        $this->queueSync($task);
    }

    public function updated(Task $task): void
    {
        if ($task->wasChanged(['title', 'description', 'due_at', 'archived_at'])) {
            $this->queueSync($task);
        }
    }

    public function deleted(Task $task): void
    {
        $this->queueSync($task);
    }

    private function hasImportedSchedule(Task $task): bool
    {
        return IntegrationConnection::query()
            ->where('user_id', $task->user_id)
            ->where('provider', 'google')
            ->whereHas('mappings', fn ($query) => $query
                ->where('resource_type', 'task_schedule')
                ->where('status', 'imported')
                ->whereIn('internal_id', $task->schedules()->select('id')))
            ->exists();
    }

    private function queueSync(Task $task): void
    {
        $user = $task->user()->first();

        if ($user) {
            $this->calendarSync->queueSyncIfConnected($user);
        }
    }
}
