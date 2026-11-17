<?php

use App\Models\Assigner;
use App\Models\Category;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

test('calendar page is available and creating from a date keeps schedule separate from deadline', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('calendar'))->assertOk()->assertSee('deadline resmi ditampilkan terpisah');

    Livewire::test('pages::calendar')
        ->set('selectedDate', '2026-11-12')
        ->set('newTaskTitle', 'Persiapan presentasi')
        ->call('createTaskOnDate')
        ->assertHasNoErrors();

    $task = $user->tasks()->where('title', 'Persiapan presentasi')->firstOrFail();
    $schedule = $task->schedules()->firstOrFail();

    expect($task->due_at)->toBeNull()
        ->and($schedule->starts_at->toDateString())->toBe('2026-11-12')
        ->and($schedule->starts_at->diffInMinutes($schedule->ends_at))->toBe(60.0);
});

test('rescheduling a task changes only its work schedule and is user scoped', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $task = Task::factory()->for($owner)->create(['due_at' => '2026-11-20 23:59:00']);
    $schedule = $task->schedules()->create([
        'user_id' => $owner->id,
        'starts_at' => '2026-11-10 14:30:00',
        'ends_at' => '2026-11-10 16:30:00',
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::calendar')
        ->call('scheduleTask', $task->id, '2026-11-15')
        ->assertHasNoErrors();

    expect($schedule->fresh()->starts_at->toDateString())->toBe('2026-11-15')
        ->and($schedule->fresh()->starts_at->format('H:i:s'))->toBe('14:30:00')
        ->and($schedule->fresh()->starts_at->diffInMinutes($schedule->fresh()->ends_at))->toBe(120.0)
        ->and($task->fresh()->due_at->toDateString())->toBe('2026-11-20');

    $otherTask = Task::factory()->for($otherUser)->create();

    Livewire::test('pages::calendar')
        ->call('scheduleTask', $otherTask->id, '2026-11-16')
        ->assertNotFound();
});

test('dragging one calendar session leaves other sessions and the official deadline intact', function () {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create([
        'status' => 'in_progress',
        'due_at' => today()->addDays(5)->setTime(23, 59),
    ]);
    $movedSchedule = $task->schedules()->create([
        'user_id' => $user->id,
        'starts_at' => today()->setTime(9, 0),
        'ends_at' => today()->setTime(10, 30),
    ]);
    $unchangedSchedule = $task->schedules()->create([
        'user_id' => $user->id,
        'starts_at' => today()->setTime(14, 0),
        'ends_at' => today()->setTime(15, 0),
    ]);
    $this->actingAs($user);

    Livewire::test('pages::calendar')
        ->set('scheduleDates.'.$movedSchedule->id, today()->addDay()->toDateString())
        ->call('moveScheduleFromCalendar', $task->id, $movedSchedule->id)
        ->assertHasNoErrors();

    expect($movedSchedule->fresh()->starts_at->toDateString())->toBe(today()->addDay()->toDateString())
        ->and($movedSchedule->fresh()->starts_at->diffInMinutes($movedSchedule->fresh()->ends_at))->toBe(90.0)
        ->and($unchangedSchedule->fresh()->starts_at->toDateString())->toBe(today()->toDateString())
        ->and($task->fresh()->due_at->toDateString())->toBe(today()->addDays(5)->toDateString());
});

test('calendar shows the visible portion of multi-day schedules without extending midnight end dates', function () {
    $this->travelTo('2026-11-04 12:00:00');

    $user = User::factory()->create();
    $multiDayTask = Task::factory()->for($user)->create(['title' => 'Lokakarya dua hari']);
    $multiDaySchedule = $multiDayTask->schedules()->create([
        'user_id' => $user->id,
        'starts_at' => '2026-11-01 22:00:00',
        'ends_at' => '2026-11-03 01:00:00',
    ]);
    $finishedAtMidnightTask = Task::factory()->for($user)->create(['title' => 'Sesi sebelum minggu ini']);
    $finishedAtMidnightSchedule = $finishedAtMidnightTask->schedules()->create([
        'user_id' => $user->id,
        'starts_at' => '2026-11-01 23:00:00',
        'ends_at' => '2026-11-02 00:00:00',
    ]);
    $this->actingAs($user);

    $calendar = Livewire::test('pages::calendar')
        ->set('currentDate', '2026-11-04')
        ->set('viewMode', 'week');
    $events = $calendar->get('events');

    expect(array_column($events['2026-11-02'], 'schedule_id'))->toContain($multiDaySchedule->id)
        ->and(array_column($events['2026-11-03'], 'schedule_id'))->toContain($multiDaySchedule->id)
        ->and(collect($events['2026-11-02'])->firstWhere('schedule_id', $multiDaySchedule->id)['time'])->toBe('Berlanjut')
        ->and(collect($events['2026-11-03'])->firstWhere('schedule_id', $multiDaySchedule->id)['time'])->toBe('Sampai 01:00')
        ->and(array_column($events['2026-11-02'], 'schedule_id'))->not->toContain($finishedAtMidnightSchedule->id)
        ->and($calendar->get('scheduleDates'))->toBe([$multiDaySchedule->id => '2026-11-01'])
        ->and($calendar->get('periodProgress')['total'])->toBe(1);
});

test('calendar marks deadlines inside the configured urgency window', function () {
    $this->travelTo(now()->setDate(2026, 11, 7)->setTime(12, 0));
    $user = User::factory()->create([
        'settings' => ['deadline_urgency' => ['urgent_days' => 2]],
    ]);
    Task::factory()->for($user)->create([
        'title' => 'Deadline mendekat',
        'status' => 'todo',
        'priority' => 'low',
        'due_at' => now()->addDay(),
    ]);
    Task::factory()->for($user)->create([
        'title' => 'Deadline masih jauh',
        'status' => 'todo',
        'priority' => 'low',
        'due_at' => now()->addDays(5),
    ]);
    $this->actingAs($user);

    Livewire::test('pages::calendar')
        ->set('currentDate', today()->toDateString())
        ->assertSee('URGENT')
        ->assertSee('Deadline mendekat')
        ->assertSee('Deadline masih jauh');
});

test('calendar period summary includes child deadlines and schedules without duplicating their project', function () {
    $user = User::factory()->create();
    $project = Task::factory()->for($user)->create(['title' => 'Proyek periode ini']);
    $subtask = Task::factory()->for($user)->create([
        'parent_task_id' => $project->id,
        'title' => 'Subtask periode ini',
        'due_at' => today()->setTime(18, 0),
    ]);
    $subtask->items()->create(['title' => 'Selesai', 'position' => 0, 'is_completed' => true]);
    $subtask->items()->create(['title' => 'Belum selesai', 'position' => 1, 'is_completed' => false]);
    $subtask->schedules()->create([
        'user_id' => $user->id,
        'starts_at' => today()->setTime(15, 0),
        'ends_at' => today()->setTime(16, 0),
    ]);
    $this->actingAs($user);

    $summary = Livewire::test('pages::calendar')
        ->set('currentDate', today()->toDateString())
        ->get('periodProgress');

    expect($summary['total'])->toBe(1)
        ->and($summary['average'])->toBe(50);
});

test('calendar period summary filters projects by matching subtask category and assigner', function () {
    $user = User::factory()->create();
    $category = Category::factory()->for($user)->create();
    $assigner = Assigner::factory()->for($user)->create();
    $project = Task::factory()->for($user)->create(['title' => 'Proyek dengan kategori berbeda']);
    $subtask = Task::factory()->for($user)->create([
        'parent_task_id' => $project->id,
        'category_id' => $category->id,
        'title' => 'Subtask yang cocok filter',
        'due_at' => today()->setTime(18, 0),
    ]);
    $subtask->assigners()->attach($assigner);
    $subtask->items()->create(['title' => 'Selesai', 'position' => 0, 'is_completed' => true]);
    $subtask->items()->create(['title' => 'Belum selesai', 'position' => 1, 'is_completed' => false]);
    $this->actingAs($user);

    $summary = Livewire::test('pages::calendar')
        ->set('currentDate', today()->toDateString())
        ->set('categoryFilter', (string) $category->id)
        ->set('assignerFilter', (string) $assigner->id)
        ->get('periodProgress');

    expect($summary['total'])->toBe(1)
        ->and($summary['average'])->toBe(50)
        ->and($summary['completed'])->toBe(0);
});

test('calendar cannot reschedule an archived task', function () {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create(['archived_at' => now()]);
    $this->actingAs($user);

    Livewire::test('pages::calendar')
        ->call('scheduleTask', $task->id, today()->addDay()->toDateString())
        ->assertNotFound();

    expect($task->schedules()->count())->toBe(0);
});

test('clicking a calendar event opens its task details and stays user scoped', function () {
    $owner = User::factory()->create();
    $task = Task::factory()->for($owner)->create([
        'title' => 'Tugas desain basis data',
        'description' => 'Buat ERD dan normalisasi tabel.',
        'due_at' => '2026-11-20 23:59:00',
    ]);
    $task->items()->create(['title' => 'Gambar ERD', 'position' => 0, 'is_completed' => true]);
    $task->items()->create(['title' => 'Tulis normalisasi', 'position' => 1]);
    $task->schedules()->create([
        'user_id' => $owner->id,
        'starts_at' => '2026-11-09 09:00:00',
        'ends_at' => '2026-11-09 10:30:00',
        'label' => 'Sesi desain',
    ]);
    $task->taskNotes()->create(['user_id' => $owner->id, 'body' => 'Gunakan notasi crow’s foot.']);
    $this->actingAs($owner);

    Livewire::test('pages::calendar')
        ->call('showTask', $task->id)
        ->assertSet('selectedTaskId', $task->id)
        ->assertSee('Detail tugas')
        ->assertSee('Buat ERD dan normalisasi tabel.')
        ->assertSee('Gambar ERD')
        ->assertSee('Tulis normalisasi')
        ->assertSee('Jadwal pengerjaan')
        ->assertSee('Sesi desain')
        ->assertSee('Gunakan notasi crow’s foot.')
        ->assertSee('50%');

    $otherUser = User::factory()->create();
    $otherTask = Task::factory()->for($otherUser)->create();

    Livewire::test('pages::calendar')
        ->call('showTask', $otherTask->id)
        ->assertNotFound();
});
