<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BackupExportController;
use App\Http\Controllers\ExternalOAuthController;
use App\Http\Controllers\TaskFileDownloadController;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\TaskSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return view('welcome');
})->name('home');

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->name('login.store');
    Route::get('two-factor-challenge', [AuthController::class, 'showTwoFactorChallenge'])->name('two-factor.login');
    Route::post('two-factor-challenge', [AuthController::class, 'verifyTwoFactorChallenge'])->middleware('throttle:10,1')->name('two-factor.login.store');
    Route::get('register', [AuthController::class, 'showRegistration'])->name('register');
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:5,1')->name('register.store');
});

Route::post('logout', [AuthController::class, 'logout'])->middleware(['auth', 'auth.session'])->name('logout');
Route::get('password/confirm', [AuthController::class, 'showPasswordConfirmation'])->middleware(['auth', 'auth.session'])->name('password.confirm');
Route::post('password/confirm', [AuthController::class, 'confirmPassword'])->middleware(['auth', 'auth.session', 'throttle:5,1'])->name('password.confirm.store');
Route::get('forgot-password', [AuthController::class, 'showForgotPassword'])->middleware('guest')->name('password.request');
Route::post('forgot-password', [AuthController::class, 'sendResetLink'])->middleware(['guest', 'throttle:5,1'])->name('password.email');
Route::get('reset-password/{token}', [AuthController::class, 'showResetPassword'])->middleware('guest')->name('password.reset');
Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware(['guest', 'throttle:5,1'])->name('password.update');
Route::middleware(['auth', 'auth.session'])->group(function (): void {
    Route::livewire('integrations', 'pages::integrations')->name('integrations.index');
    Route::livewire('assistant', 'pages::assistant')->name('assistant');
    Route::get('integrations/google/redirect', [ExternalOAuthController::class, 'redirectToGoogle'])->name('integrations.google.redirect');
    Route::get('integrations/google/callback', [ExternalOAuthController::class, 'handleGoogleCallback'])->name('integrations.google.callback');
    Route::post('integrations/google/picker-token', [ExternalOAuthController::class, 'googlePickerToken'])->middleware('throttle:30,1')->name('integrations.google.picker-token');
    Route::delete('integrations/google', [ExternalOAuthController::class, 'disconnectGoogle'])->name('integrations.google.disconnect');
    Route::get('integrations/github/redirect', [ExternalOAuthController::class, 'redirectToGitHub'])->name('integrations.github.redirect');
    Route::get('integrations/github/callback', [ExternalOAuthController::class, 'handleGitHubCallback'])->name('integrations.github.callback');
    Route::delete('integrations/github', [ExternalOAuthController::class, 'disconnectGitHub'])->name('integrations.github.disconnect');
});

Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::post('reminders/{reminder}/dismiss', function (Reminder $reminder) {
        auth()->user()->reminders()->findOrFail($reminder->id)->update(['dismissed_at' => now()]);

        return back();
    })->name('reminders.dismiss');

    Route::get('dashboard', function () {
        /** @var User $user */
        $user = auth()->user();
        $tasks = $user->tasks()
            ->whereNull('parent_task_id')
            ->whereNull('archived_at')
            ->with(['items', 'children.items', 'schedules', 'category', 'course', 'assigners'])
            ->get();
        $allTasks = $user->tasks()
            ->whereNull('archived_at')
            ->with(['category', 'course'])
            ->get();
        $taskParents = $user->tasks()->pluck('parent_task_id', 'id');
        $topLevelTaskId = static function (int $taskId) use ($taskParents): int {
            $visitedIds = [];

            while (($parentTaskId = $taskParents->get($taskId)) !== null && ! in_array($taskId, $visitedIds, true)) {
                $visitedIds[] = $taskId;
                $taskId = (int) $parentTaskId;
            }

            return $taskId;
        };
        $periodProgress = static function (CarbonImmutable $start, CarbonImmutable $end) use ($tasks, $user, $topLevelTaskId): int {
            $dueRootIds = $user->tasks()
                ->whereNull('archived_at')
                ->whereBetween('due_at', [$start, $end])
                ->pluck('id')
                ->map(fn ($taskId): int => $topLevelTaskId((int) $taskId));
            $scheduledRootIds = $user->taskSchedules()
                ->whereHas('task', fn (Builder $query) => $query->whereNull('archived_at'))
                ->where('starts_at', '<=', $end)
                ->where('ends_at', '>', $start)
                ->pluck('task_id')
                ->map(fn ($taskId): int => $topLevelTaskId((int) $taskId))
                ->unique();
            $periodRootIds = $dueRootIds->merge($scheduledRootIds)->unique();
            $periodTasks = $tasks->whereIn('id', $periodRootIds);

            return $periodTasks->isEmpty() ? 0 : (int) round($periodTasks->average(fn ($task): int => $task->computedProgress()));
        };
        $activeSemester = $user->semesters()->where('is_active', true)->first();
        $integrationConnections = $user->integrations()
            ->get(['id', 'provider', 'status', 'last_synced_at', 'last_error', 'account_label']);
        $reminders = $user->reminders()
            ->with('task')
            ->whereHas('task', fn ($query) => $query->whereNull('archived_at'))
            ->whereNull('dismissed_at')
            ->where('remind_at', '<=', now()->addDays(7))
            ->orderBy('remind_at')
            ->limit(8)
            ->get();
        $today = CarbonImmutable::today();
        $todayTasks = $user->tasks()
            ->whereNull('archived_at')
            ->where('status', '!=', 'done')
            ->where(function (Builder $query) use ($today): void {
                $query->whereBetween('due_at', [$today->startOfDay(), $today->endOfDay()])
                    ->orWhereHas('schedules', fn (Builder $schedules) => $schedules
                        ->where('starts_at', '<=', $today->endOfDay())
                        ->where('ends_at', '>', $today->startOfDay()));
            })
            ->with(['schedules' => fn (HasMany $schedules) => $schedules
                ->where('starts_at', '<=', $today->endOfDay())
                ->where('ends_at', '>', $today->startOfDay())
                ->orderBy('starts_at')])
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderBy('due_at')
            ->get();
        $calendarStart = $today->startOfDay();
        $calendarEnd = $today->addDays(6)->endOfDay();
        $scheduledPreview = $user->taskSchedules()
            ->whereHas('task', fn (Builder $tasks) => $tasks->whereNull('archived_at'))
            ->where('starts_at', '<=', $calendarEnd)
            ->where('ends_at', '>', $calendarStart)
            ->with('task:id,title,priority')
            ->get()
            ->map(fn (TaskSchedule $schedule): array => [
                'task' => $schedule->task->title,
                'at' => $schedule->starts_at,
                'kind' => 'Jadwal pengerjaan',
                'priority' => $schedule->task->priority,
            ]);
        $deadlinePreview = $user->tasks()
            ->whereNull('archived_at')
            ->whereBetween('due_at', [$calendarStart, $calendarEnd])
            ->get()
            ->map(fn (Task $task): array => [
                'task' => $task->title,
                'at' => $task->due_at,
                'kind' => 'Deadline',
                'priority' => $task->priority,
            ]);
        $calendarPreview = $scheduledPreview
            ->concat($deadlinePreview)
            ->sortBy(fn (array $event): int => $event['at']->getTimestamp())
            ->take(8)
            ->values();
        $nextDeadlines = $allTasks
            ->filter(fn ($task): bool => $task->due_at && $task->due_at->isFuture() && $task->status !== 'done')
            ->sortBy('due_at')
            ->take(5);
        $deadlineGroups = [
            'Overdue' => collect(),
            'Critical' => collect(),
            'Urgent' => collect(),
            'Upcoming' => collect(),
            'Planned' => collect(),
        ];
        $deadlineTasks = $allTasks
            ->filter(fn (Task $task): bool => $task->due_at !== null && $task->status !== 'done');

        $urgencySettings = $user->settings['deadline_urgency'] ?? [];
        $criticalHours = (int) ($urgencySettings['critical_hours'] ?? 24);
        $urgentDays = (int) ($urgencySettings['urgent_days'] ?? 3);
        $upcomingDays = (int) ($urgencySettings['upcoming_days'] ?? 7);

        foreach ($deadlineTasks as $deadlineTask) {
            $timeUntilDeadline = now()->diffInMinutes($deadlineTask->due_at, absolute: true);
            $urgency = $deadlineTask->due_at->isPast()
                ? 'Overdue'
                : match (true) {
                    $timeUntilDeadline <= $criticalHours * 60 => 'Critical',
                    $timeUntilDeadline <= $urgentDays * 24 * 60 => 'Urgent',
                    $timeUntilDeadline <= $upcomingDays * 24 * 60 => 'Upcoming',
                    default => 'Planned',
                };

            $deadlineGroups[$urgency]->push($deadlineTask);
        }

        foreach ($deadlineGroups as $urgency => $group) {
            $deadlineGroups[$urgency] = $group
                ->sortBy(fn ($task): array => [
                    $task->due_at->timestamp,
                    match ($task->priority) {
                        'urgent' => 0, 'high' => 1, 'medium' => 2, default => 3
                    },
                ])
                ->values();
        }
        $priorityTasks = $allTasks
            ->filter(fn ($task): bool => $task->status !== 'done')
            ->sortBy(fn ($task): array => [
                match ($task->priority) {
                    'urgent' => 0, 'high' => 1, 'medium' => 2, default => 3
                },
                isset($task->due_at) ? $task->due_at->timestamp : PHP_INT_MAX,
            ])
            ->take(4);
        $weeklyCompletions = collect(range(6, 0))->map(function (int $daysAgo) use ($user): array {
            $date = today()->subDays($daysAgo);

            return [
                'label' => $date->translatedFormat('D'),
                'count' => $user->taskStatusEvents()->where('to_status', 'done')->whereDate('created_at', $date)->count(),
            ];
        });
        $maxCompletions = max(1, (int) $weeklyCompletions->max('count'));

        return view('dashboard', [
            'foundationCounts' => [
                'semesters' => $user->semesters()->count(),
                'courses' => $user->courses()->count(),
                'categories' => $user->categories()->count(),
                'assigners' => $user->assigners()->count(),
            ],
            'tasks' => $tasks,
            'activeTasks' => $allTasks->where('status', '!=', 'done')->count(),
            'completedTasks' => $allTasks->where('status', 'done')->count(),
            'overdueTasks' => $allTasks->filter(fn ($task): bool => $task->due_at && $task->due_at->isPast() && $task->status !== 'done')->count(),
            'todayTasks' => $todayTasks,
            'calendarPreview' => $calendarPreview,
            'nextDeadlines' => $nextDeadlines,
            'deadlineGroups' => $deadlineGroups,
            'priorityTasks' => $priorityTasks,
            'weeklyCompletions' => $weeklyCompletions,
            'maxCompletions' => $maxCompletions,
            'progressPeriods' => [
                'Harian' => $periodProgress(CarbonImmutable::today(), CarbonImmutable::tomorrow()->subSecond()),
                'Mingguan' => $periodProgress(CarbonImmutable::now()->startOfWeek(), CarbonImmutable::now()->endOfWeek()),
                'Bulanan' => $periodProgress(CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->endOfMonth()),
                'Semester' => $activeSemester
                    ? $periodProgress(CarbonImmutable::parse($activeSemester->starts_at), CarbonImmutable::parse($activeSemester->ends_at)->endOfDay())
                    : 0,
            ],
            'activeSemester' => $activeSemester,
            'integrationConnections' => $integrationConnections,
            'recentActivity' => $user->taskStatusEvents()->with('task')->latest()->limit(5)->get(),
            'reminders' => $reminders,
        ]);
    })->name('dashboard');

    Route::livewire('workspace', 'pages::workspace-settings')->name('workspace');
    Route::livewire('tasks', 'pages::tasks')->name('tasks.index');
    Route::livewire('calendar', 'pages::calendar')->name('calendar');
    Route::livewire('files', 'pages::files')->name('files.index');
    Route::get('files/{taskFile}/download', TaskFileDownloadController::class)->name('files.download');
    Route::get('settings/backup', BackupExportController::class)->middleware('throttle:5,1')->name('backup.download');
    Route::livewire('submissions', 'pages::submissions')->name('submissions.index');
    Route::livewire('recurrence', 'pages::recurrence')->name('recurrence.index');
    Route::livewire('habits', 'pages::habits')->name('habits.index');
    Route::livewire('analytics', 'pages::analytics')->name('analytics.index');
});

require __DIR__.'/settings.php';
