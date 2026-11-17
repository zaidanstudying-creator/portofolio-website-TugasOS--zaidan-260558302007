<?php

use App\Models\Task;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('guests see the TUGAS!OS landing page instead of the Laravel welcome page', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('TUGAS!OS')
        ->assertDontSee('Laravel has an incredibly rich ecosystem');
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('dashboard task totals include completed overdue and upcoming subtasks', function () {
    $user = User::factory()->create();
    $project = Task::factory()->for($user)->create([
        'title' => 'Proyek semester',
        'priority' => 'low',
        'status' => 'todo',
    ]);
    Task::factory()->for($user)->create([
        'parent_task_id' => $project->id,
        'title' => 'Subtask selesai',
        'status' => 'done',
    ]);
    Task::factory()->for($user)->create([
        'parent_task_id' => $project->id,
        'title' => 'Subtask terlambat',
        'status' => 'in_progress',
        'priority' => 'low',
        'due_at' => now()->subHour(),
    ]);
    $urgentSubtask = Task::factory()->for($user)->create([
        'parent_task_id' => $project->id,
        'title' => 'Subtask prioritas',
        'status' => 'todo',
        'priority' => 'urgent',
        'due_at' => now()->addDays(2),
    ]);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'))->assertOk();

    expect($response->viewData('activeTasks'))->toBe(3)
        ->and($response->viewData('completedTasks'))->toBe(1)
        ->and($response->viewData('overdueTasks'))->toBe(1)
        ->and($response->viewData('priorityTasks')->first()->is($urgentSubtask))->toBeTrue()
        ->and($response->viewData('nextDeadlines')->contains('id', $urgentSubtask->id))->toBeTrue();
});

test('authenticated users can open every primary workspace menu', function () {
    $this->actingAs(User::factory()->create());

    $primaryRoutes = ['dashboard', 'tasks.index', 'calendar', 'files.index', 'submissions.index', 'assistant', 'recurrence.index', 'habits.index', 'analytics.index', 'integrations.index', 'workspace'];
    $dashboard = $this->get(route('dashboard'))->assertOk();

    foreach ($primaryRoutes as $routeName) {
        $dashboard->assertSee('href="'.route($routeName).'"', false);
        $this->get(route($routeName))->assertOk();
    }

    foreach (['profile.edit', 'appearance.edit', 'security.edit'] as $routeName) {
        $this->get(route($routeName))->assertOk();
    }
});

test('dashboard lists upcoming reminders and lets the owner dismiss them', function () {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create(['title' => 'Ulangi materi']);
    $reminder = $user->reminders()->create([
        'task_id' => $task->id,
        'remind_at' => now()->addHour(),
        'message' => 'Baca ulang catatan',
    ]);
    $this->actingAs($user);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Ulangi materi')
        ->assertSee('Baca ulang catatan');

    $this->post(route('reminders.dismiss', $reminder))
        ->assertRedirect();

    expect($reminder->fresh()->dismissed_at)->not->toBeNull();

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Baca ulang catatan');
});

test('a user cannot dismiss another users reminder', function () {
    $owner = User::factory()->create();
    $task = Task::factory()->for($owner)->create();
    $reminder = $owner->reminders()->create([
        'task_id' => $task->id,
        'remind_at' => now()->addHour(),
    ]);
    $this->actingAs(User::factory()->create());

    $this->post(route('reminders.dismiss', $reminder))
        ->assertNotFound();

    expect($reminder->fresh()->dismissed_at)->toBeNull();
});

test('dashboard shows the signed in users external integration status', function () {
    $user = User::factory()->create();
    $user->integrations()->create([
        'provider' => 'google',
        'status' => 'connected',
        'last_synced_at' => now()->subHour(),
    ]);
    $user->integrations()->create([
        'provider' => 'github',
        'status' => 'connected',
        'last_error' => 'Koneksi perlu diperiksa.',
    ]);
    $this->actingAs($user);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Status sinkronisasi')
        ->assertSee('Google Calendar & Drive')
        ->assertSee('Sync terakhir')
        ->assertSee('Perlu perhatian');
});

test('dashboard focus today includes scheduled subtasks', function () {
    $user = User::factory()->create();
    $parent = Task::factory()->for($user)->create(['title' => 'Proyek hari ini']);
    $subtask = Task::factory()->for($user)->create([
        'parent_task_id' => $parent->id,
        'title' => 'Review implementasi',
        'status' => 'in_progress',
    ]);
    $subtask->schedules()->create([
        'user_id' => $user->id,
        'starts_at' => today()->setTime(15, 0),
        'ends_at' => today()->setTime(16, 0),
    ]);

    expect($user->tasks()->whereKey($subtask->id)->whereHas('schedules', fn ($query) => $query->whereDate('starts_at', today()->toDateString()))->exists())->toBeTrue();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Review implementasi')
        ->assertSee('15:00');
});

test('dashboard period progress includes projects scheduled through a subtask', function () {
    $user = User::factory()->create();
    $project = Task::factory()->for($user)->create(['title' => 'Proyek terjadwal']);
    $subtask = Task::factory()->for($user)->create([
        'parent_task_id' => $project->id,
        'title' => 'Bagian yang dikerjakan hari ini',
        'weight' => 1,
    ]);
    $subtask->items()->create(['title' => 'Langkah selesai', 'position' => 0, 'is_completed' => true]);
    $subtask->items()->create(['title' => 'Langkah berikutnya', 'position' => 1, 'is_completed' => false]);
    $subtask->schedules()->create([
        'user_id' => $user->id,
        'starts_at' => today()->setTime(15, 0),
        'ends_at' => today()->setTime(16, 0),
    ]);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'))->assertOk();

    expect($response->getContent())->toContain('<progress value="50" max="100" aria-label="Progres Harian"');
});

test('dashboard period progress includes projects with a subtask deadline', function () {
    $user = User::factory()->create();
    $project = Task::factory()->for($user)->create(['title' => 'Proyek tanpa deadline induk']);
    $subtask = Task::factory()->for($user)->create([
        'parent_task_id' => $project->id,
        'title' => 'Bagian dengan deadline hari ini',
        'due_at' => today()->setTime(18, 0),
    ]);
    $subtask->items()->create(['title' => 'Langkah selesai', 'position' => 0, 'is_completed' => true]);
    $subtask->items()->create(['title' => 'Langkah berikutnya', 'position' => 1, 'is_completed' => false]);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'))->assertOk();

    expect($response->getContent())->toContain('<progress value="50" max="100" aria-label="Progres Harian"');
});

test('dashboard calendar summary keeps work schedules and deadlines distinct', function () {
    $user = User::factory()->create();
    $scheduledTask = Task::factory()->for($user)->create([
        'title' => 'Sesi pengerjaan',
        'status' => 'in_progress',
    ]);
    $scheduledTask->schedules()->create([
        'user_id' => $user->id,
        'starts_at' => now()->addDay()->setTime(10, 0),
        'ends_at' => now()->addDay()->setTime(11, 0),
    ]);
    Task::factory()->for($user)->create([
        'title' => 'Deadline esai',
        'status' => 'todo',
        'due_at' => now()->addDays(2),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Ringkasan kalender')
        ->assertSee('Sesi pengerjaan')
        ->assertSee('Jadwal pengerjaan')
        ->assertSee('Deadline esai')
        ->assertSee('Deadline');
});

test('dashboard includes overnight work schedules in today focus and period progress', function () {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create([
        'title' => 'Sesi lintas tengah malam',
        'status' => 'in_progress',
    ]);
    $task->items()->create(['title' => 'Bagian selesai', 'position' => 0, 'is_completed' => true]);
    $task->items()->create(['title' => 'Bagian berikutnya', 'position' => 1, 'is_completed' => false]);
    $task->schedules()->create([
        'user_id' => $user->id,
        'starts_at' => today()->subDay()->setTime(23, 0),
        'ends_at' => today()->setTime(1, 0),
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $todayTask = $response->viewData('todayTasks')->firstWhere('id', $task->id);

    expect($todayTask)->not->toBeNull()
        ->and($todayTask->schedules)->toHaveCount(1)
        ->and($response->viewData('calendarPreview')->contains(fn (array $event): bool => $event['task'] === $task->title && $event['kind'] === 'Jadwal pengerjaan'))->toBeTrue()
        ->and($response->viewData('progressPeriods')['Harian'])->toBe(50);
});
