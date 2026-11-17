<?php

use App\Models\Assigner;
use App\Models\Category;
use App\Models\Course;
use App\Models\RecurrenceRule;
use App\Models\Semester;
use App\Models\Task;
use App\Models\User;
use App\Services\RecurringTaskGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('recurring task generation is idempotent and copies an unchecked checklist', function () {
    $this->travelTo(now()->setTime(12, 0));
    $user = User::factory()->create();
    $template = Task::factory()->for($user)->create(['title' => 'Cek laporan mingguan']);
    $template->items()->create(['title' => 'Periksa data', 'position' => 0, 'is_completed' => true]);
    $rule = $user->recurrenceRules()->create([
        'template_task_id' => $template->id,
        'frequency' => 'daily',
        'interval' => 1,
        'starts_on' => today()->toDateString(),
        'ends_on' => today()->addDays(2)->toDateString(),
        'next_run_at' => today()->toDateString(),
        'is_active' => true,
    ]);

    $this->artisan('tasks:generate-recurring')->assertExitCode(0);
    $this->artisan('tasks:generate-recurring')->assertExitCode(0);

    $instances = $rule->tasks()->with('items')->get();

    expect($instances)->toHaveCount(3)
        ->and($instances->every(fn (Task $task): bool => $task->items->first()->is_completed === false))->toBeTrue()
        ->and($rule->fresh()->is_active)->toBeFalse();
});

test('manual recurrence generation only processes the signed in users rules', function () {
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();

    foreach ([$firstUser, $secondUser] as $user) {
        $template = Task::factory()->for($user)->create();
        $user->recurrenceRules()->create([
            'template_task_id' => $template->id,
            'frequency' => 'daily',
            'interval' => 1,
            'starts_on' => today()->toDateString(),
            'ends_on' => today()->toDateString(),
            'next_run_at' => today()->toDateString(),
            'is_active' => true,
        ]);
    }

    $this->actingAs($firstUser);

    Livewire::test('pages::recurrence')->call('generateNow')->assertHasNoErrors();

    expect($firstUser->tasks()->whereNotNull('recurrence_rule_id')->count())->toBe(1)
        ->and($secondUser->tasks()->whereNotNull('recurrence_rule_id')->count())->toBe(0)
        ->and($secondUser->recurrenceRules()->where('is_active', true)->exists())->toBeTrue();
});

test('recurring task generation records a changed template progress exactly once', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 12:00:00'));
    $user = User::factory()->create();
    $template = Task::factory()->for($user)->create(['title' => 'Review mingguan', 'weight' => 1]);
    $completedTask = Task::factory()->for($user)->create([
        'parent_task_id' => $template->id,
        'status' => 'done',
        'weight' => 1,
    ]);
    $rule = $user->recurrenceRules()->create([
        'template_task_id' => $template->id,
        'frequency' => 'daily',
        'interval' => 1,
        'starts_on' => today()->toDateString(),
        'ends_on' => today()->toDateString(),
        'next_run_at' => today()->toDateString(),
        'is_active' => true,
    ]);

    expect($template->computedProgress())->toBe(100);

    app(RecurringTaskGenerator::class)->generate(0);

    $generatedTask = $rule->tasks()->firstOrFail();
    $progressEvent = $template->progressEvents()->firstOrFail();

    expect($generatedTask->parent_task_id)->toBe($template->id)
        ->and($template->fresh()->computedProgress())->toBe(50)
        ->and($progressEvent->from_progress)->toBe(100)
        ->and($progressEvent->to_progress)->toBe(50);

    app(RecurringTaskGenerator::class)->generate(0);

    expect($template->progressEvents()->count())->toBe(1)
        ->and($completedTask->fresh())->not->toBeNull();
});

test('weekly recurrence rules honor selected weekdays and skip initial unmatched days', function () {
    $user = User::factory()->create();
    $template = Task::factory()->for($user)->create();
    $start = today();
    $monday = $start->copy();

    while ($monday->dayOfWeek !== 1) {
        $monday = $monday->addDay();
    }

    $rule = RecurrenceRule::query()->create([
        'user_id' => $user->id,
        'template_task_id' => $template->id,
        'frequency' => 'weekly',
        'interval' => 1,
        'weekdays' => [1],
        'starts_on' => $start->toDateString(),
        'ends_on' => $monday->toDateString(),
        'next_run_at' => $start->toDateString(),
        'is_active' => true,
    ]);

    app(RecurringTaskGenerator::class)->generate();

    expect($rule->tasks()->firstOrFail()->recurrence_occurrence_date->toDateString())->toBe($monday->toDateString());
});

test('monthly recurrence returns to its anchor day after short months', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-31 12:00:00'));
    $user = User::factory()->create();
    $template = Task::factory()->for($user)->create();
    $rule = $user->recurrenceRules()->create([
        'template_task_id' => $template->id,
        'frequency' => 'monthly',
        'interval' => 1,
        'starts_on' => '2026-01-31',
        'ends_on' => '2026-04-30',
        'next_run_at' => '2026-01-31',
        'is_active' => true,
    ]);

    app(RecurringTaskGenerator::class)->generate(90);

    expect($rule->tasks()->orderBy('recurrence_occurrence_date')->pluck('recurrence_occurrence_date')->map(
        fn (CarbonImmutable|string $date): string => CarbonImmutable::parse($date)->toDateString(),
    )->all())->toBe(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'])
        ->and($rule->fresh()->is_active)->toBeFalse();
});

test('recurring task generation copies scheduled work sessions relative to each occurrence', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-31 12:00:00'));
    $user = User::factory()->create();
    $template = Task::factory()->for($user)->create();
    $template->schedules()->create([
        'user_id' => $user->id,
        'starts_at' => '2026-01-31 14:30:00',
        'ends_at' => '2026-01-31 16:00:00',
        'is_all_day' => false,
        'label' => 'Sesi pengerjaan',
    ]);
    $rule = $user->recurrenceRules()->create([
        'template_task_id' => $template->id,
        'frequency' => 'monthly',
        'interval' => 1,
        'starts_on' => '2026-01-31',
        'ends_on' => '2026-03-31',
        'next_run_at' => '2026-01-31',
        'is_active' => true,
    ]);

    app(RecurringTaskGenerator::class)->generate(60);

    $instances = $rule->tasks()->with('schedules')->orderBy('recurrence_occurrence_date')->get();

    expect($instances)->toHaveCount(3)
        ->and($instances->map(fn (Task $task): array => [
            $task->schedules->first()->starts_at->format('Y-m-d H:i'),
            $task->schedules->first()->ends_at->format('Y-m-d H:i'),
            $task->schedules->first()->label,
        ])->all())->toBe([
            ['2026-01-31 14:30', '2026-01-31 16:00', 'Sesi pengerjaan'],
            ['2026-02-28 14:30', '2026-02-28 16:00', 'Sesi pengerjaan'],
            ['2026-03-31 14:30', '2026-03-31 16:00', 'Sesi pengerjaan'],
        ]);
});

test('a user can create a habit and toggle today check-in', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::habits')
        ->set('name', 'Baca materi')
        ->set('targetPerWeek', '5')
        ->call('createHabit')
        ->assertHasNoErrors();

    $habit = $user->habits()->firstOrFail();

    Livewire::test('pages::habits')
        ->call('checkIn', $habit->id)
        ->assertHasNoErrors();

    expect($habit->entries()->count())->toBe(1)
        ->and($habit->relationLoaded('entries'))->toBeFalse()
        ->and($habit->currentStreak())->toBe(1)
        ->and($habit->relationLoaded('entries'))->toBeFalse()
        ->and($habit->consistency())->toBeGreaterThan(0);

    Livewire::test('pages::habits')->call('checkIn', $habit->id);

    expect($habit->entries()->count())->toBe(0);
});

test('habit streak and consistency reuse eager loaded check-ins', function () {
    $user = User::factory()->create();
    $habit = $user->habits()->create([
        'name' => 'Baca materi',
        'target_per_week' => 5,
    ]);
    $habit->entries()->create(['entry_date' => today()->subDay()->toDateString()]);
    $habit->entries()->create(['entry_date' => today()->toDateString()]);
    $loadedHabit = $user->habits()->with('entries')->findOrFail($habit->id);

    DB::connection()->enableQueryLog();
    $before = count(DB::connection()->getQueryLog());

    expect($loadedHabit->currentStreak())->toBe(2)
        ->and($loadedHabit->consistency(7))->toBe(40);

    expect(count(DB::connection()->getQueryLog()) - $before)->toBe(0);
    DB::connection()->disableQueryLog();
});

test('habit tracker supports different consistency periods without truncating streak history', function () {
    $user = User::factory()->create();
    $habit = $user->habits()->create([
        'name' => 'Latihan soal',
        'target_per_week' => 5,
    ]);
    $habit->entries()->create(['entry_date' => today()->toDateString()]);
    $habit->entries()->create(['entry_date' => today()->subDays(2)->toDateString()]);
    $habit->entries()->create(['entry_date' => today()->subDays(10)->toDateString()]);
    $this->actingAs($user);

    $component = Livewire::test('pages::habits')
        ->assertSee('Konsistensi 30 hari')
        ->set('periodDays', '7')
        ->assertSee('Konsistensi 7 hari');

    expect($component->get('habits')->first()->entries)->toHaveCount(3)
        ->and($component->get('habits')->first()->consistency(7))->toBe(40);

    $component->set('periodDays', '90');

    expect($component->get('habits')->first()->entries)->toHaveCount(3)
        ->and($component->get('habits')->first()->consistency(90))->toBe(5);
});

test('analytics applies date and task filters to progress summaries', function () {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create([
        'title' => 'Tugas bulan ini',
        'due_at' => now()->endOfDay(),
        'status' => 'done',
        'completed_at' => now(),
    ]);
    Task::factory()->for($user)->create([
        'title' => 'Tugas bulan lalu',
        'due_at' => now()->subMonth(),
    ]);

    $this->actingAs($user)->get(route('analytics.index'))->assertOk();

    $component = Livewire::test('pages::analytics')
        ->set('period', 'monthly')
        ->set('groupBy', 'due_at')
        ->call('$refresh');

    expect($component->get('summary')['created'])->toBe(1)
        ->and($component->get('summary')['progress'])->toBe(100)
        ->and($component->get('summary')['completion'])->toBe(100);
});

test('analytics filters projects using matching nested tasks without counting the project twice', function () {
    $user = User::factory()->create();
    $category = Category::factory()->for($user)->create();
    $semester = Semester::factory()->for($user)->create();
    $course = Course::factory()->for($user)->create(['semester_id' => $semester->id]);
    $assigner = Assigner::factory()->for($user)->create();
    $assigner->roles()->create(['name' => 'Dosen']);
    $project = Task::factory()->for($user)->create([
        'title' => 'Proyek tanpa metadata turunan',
        'due_at' => null,
        'category_id' => null,
        'course_id' => null,
        'semester_id' => null,
    ]);
    $subtask = Task::factory()->for($user)->create([
        'parent_task_id' => $project->id,
        'title' => 'Subtask dengan metadata',
        'category_id' => $category->id,
        'course_id' => $course->id,
        'semester_id' => $semester->id,
        'due_at' => now()->endOfDay(),
        'priority' => 'urgent',
        'status' => 'in_progress',
        'type' => 'weekly',
    ]);
    $subtask->assigners()->attach($assigner);
    $subtask->items()->create(['title' => 'Selesai', 'position' => 0, 'is_completed' => true]);
    $subtask->items()->create(['title' => 'Belum selesai', 'position' => 1, 'is_completed' => false]);
    $this->actingAs($user);

    $component = Livewire::test('pages::analytics')
        ->set('period', 'monthly')
        ->set('groupBy', 'due_at')
        ->set('categoryId', (string) $category->id)
        ->set('assignerId', (string) $assigner->id)
        ->set('assignerRole', 'Dosen')
        ->set('courseId', (string) $course->id)
        ->set('semesterId', (string) $semester->id)
        ->set('priorityFilter', 'urgent')
        ->set('statusFilter', 'in_progress')
        ->set('typeFilter', 'weekly');

    expect($component->get('tasks')->modelKeys())->toBe([$project->id])
        ->and($component->get('summary')['created'])->toBe(1)
        ->and($component->get('summary')['progress'])->toBe(50);
});

test('assignment analytics use matching subtasks for completion deadlines and submission evidence', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 12:00:00'));

    $user = User::factory()->create();
    $category = Category::factory()->for($user)->create(['name' => 'Riset']);
    $assigner = Assigner::factory()->for($user)->create(['name' => 'Dosen Riset']);
    $project = Task::factory()->for($user)->create([
        'title' => 'Proyek semester',
        'due_at' => null,
        'category_id' => null,
    ]);
    $submittedTask = Task::factory()->for($user)->create([
        'parent_task_id' => $project->id,
        'title' => 'Laporan tepat waktu',
        'category_id' => $category->id,
        'due_at' => CarbonImmutable::parse('2026-11-07 23:59:00'),
        'status' => 'done',
        'completed_at' => CarbonImmutable::parse('2026-11-07 22:00:00'),
    ]);
    $overdueTask = Task::factory()->for($user)->create([
        'parent_task_id' => $project->id,
        'title' => 'Analisis terlambat',
        'category_id' => $category->id,
        'due_at' => CarbonImmutable::parse('2026-11-07 23:59:00'),
        'status' => 'in_progress',
    ]);
    $submittedTask->assigners()->attach($assigner);
    $overdueTask->assigners()->attach($assigner);
    $submittedTask->submissions()->create([
        'user_id' => $user->id,
        'platform' => 'manual',
        'status' => 'manual_recorded',
        'confirmation_method' => 'manual',
        'submitted_at' => CarbonImmutable::parse('2026-11-07 22:30:00'),
    ]);
    $this->actingAs($user);

    $component = Livewire::test('pages::analytics')
        ->set('period', 'custom')
        ->set('startDate', '2026-11-07')
        ->set('endDate', '2026-11-08')
        ->set('groupBy', 'due_at');

    $summary = $component->get('summary');

    expect($component->get('tasks')->modelKeys())->toBe([$project->id])
        ->and($component->get('metricTasks')->modelKeys())->toBe([$submittedTask->id, $overdueTask->id])
        ->and($summary['created'])->toBe(2)
        ->and($summary['completed'])->toBe(1)
        ->and($summary['completion'])->toBe(50)
        ->and($summary['on_time'])->toBe(50)
        ->and($summary['overdue'])->toBe(1)
        ->and($summary['by_category']->get('Riset'))->toBe(50)
        ->and($summary['by_assigner']->get('Dosen Riset'))->toBe(50);
});

test('analytics supports task count and weight based progress aggregation', function () {
    $user = User::factory()->create();
    Task::factory()->for($user)->create([
        'title' => 'Milestone besar selesai',
        'due_at' => now()->endOfDay(),
        'status' => 'done',
        'weight' => 3,
    ]);
    Task::factory()->for($user)->create([
        'title' => 'Tugas kecil tertunda',
        'due_at' => now()->endOfDay(),
        'status' => 'todo',
        'weight' => 1,
    ]);
    $this->actingAs($user);

    $component = Livewire::test('pages::analytics')
        ->set('period', 'monthly')
        ->set('groupBy', 'due_at')
        ->set('aggregationMode', 'count');

    expect($component->get('summary')['progress'])->toBe(50);

    $component->set('aggregationMode', 'weighted');

    expect($component->get('summary')['progress'])->toBe(75);
});

test('on time submission rate uses each tasks latest successful submission', function () {
    $user = User::factory()->create();
    $firstTask = Task::factory()->for($user)->create([
        'due_at' => today()->setTime(12, 0),
    ]);
    $secondTask = Task::factory()->for($user)->create([
        'due_at' => today()->setTime(12, 0),
    ]);

    $firstTask->submissions()->createMany([
        [
            'user_id' => $user->id,
            'platform' => 'manual',
            'status' => 'manual_recorded',
            'confirmation_method' => 'manual',
            'submitted_at' => today()->setTime(11, 0),
        ],
        [
            'user_id' => $user->id,
            'platform' => 'manual',
            'status' => 'manual_recorded',
            'confirmation_method' => 'manual',
            'submitted_at' => today()->setTime(13, 0),
        ],
    ]);

    $secondTask->submissions()->createMany([
        [
            'user_id' => $user->id,
            'platform' => 'manual',
            'status' => 'manual_recorded',
            'confirmation_method' => 'manual',
            'submitted_at' => today()->setTime(14, 0),
        ],
        [
            'user_id' => $user->id,
            'platform' => 'manual',
            'status' => 'manual_recorded',
            'confirmation_method' => 'manual',
            'submitted_at' => today()->setTime(15, 0),
        ],
    ]);

    $this->actingAs($user);
    $component = Livewire::test('pages::analytics')
        ->set('period', 'custom')
        ->set('startDate', today()->toDateString())
        ->set('endDate', today()->toDateString());

    expect($component->get('summary')['on_time'])->toBe(0);
});

test('on time submission rate counts overdue tasks without a submission and skips future deadlines', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 18:00:00'));

    $user = User::factory()->create();
    $submittedTask = Task::factory()->for($user)->create([
        'due_at' => CarbonImmutable::parse('2026-11-07 23:59:00'),
    ]);
    $missedTask = Task::factory()->for($user)->create([
        'due_at' => CarbonImmutable::parse('2026-11-07 23:59:00'),
    ]);
    Task::factory()->for($user)->create([
        'due_at' => CarbonImmutable::parse('2026-11-10 23:59:00'),
    ]);
    $submittedTask->submissions()->create([
        'user_id' => $user->id,
        'platform' => 'manual',
        'status' => 'manual_recorded',
        'confirmation_method' => 'manual',
        'submitted_at' => CarbonImmutable::parse('2026-11-07 23:30:00'),
    ]);
    $this->actingAs($user);

    $component = Livewire::test('pages::analytics')
        ->set('period', 'custom')
        ->set('startDate', '2026-11-01')
        ->set('endDate', '2026-11-30');

    expect($component->get('summary')['on_time'])->toBe(50)
        ->and($missedTask->fresh()->submissions)->toBeEmpty();
});

test('analytics displays a daily historical trend from the latest task progress events', function () {
    $this->travelTo(now()->setTime(12, 0));
    $user = User::factory()->create();
    $firstTask = Task::factory()->for($user)->create(['due_at' => today(), 'weight' => 3]);
    $secondTask = Task::factory()->for($user)->create(['due_at' => today(), 'weight' => 1]);
    $createdAt = today()->subDays(2)->setTime(12, 0);
    $firstTask->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();
    $secondTask->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

    foreach ([
        [$firstTask, 0, 20, today()->subDay()->setTime(9, 0)],
        [$firstTask, 20, 40, today()->setTime(8, 0)],
        [$firstTask, 40, 60, today()->setTime(10, 0)],
        [$secondTask, 0, 100, today()->setTime(9, 0)],
    ] as [$task, $from, $to, $createdAt]) {
        $event = $task->progressEvents()->create([
            'user_id' => $user->id,
            'from_progress' => $from,
            'to_progress' => $to,
        ]);
        $event->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();
    }

    $this->actingAs($user);
    $component = Livewire::test('pages::analytics')->set('aggregationMode', 'count');

    expect($component->get('progressTrend')->pluck('progress')->all())->toBe([10, 80])
        ->and($component->get('progressTrend')->last()['changes'])->toBe(3);

    $component->set('aggregationMode', 'weighted');

    expect($component->get('progressTrend')->pluck('progress')->all())->toBe([15, 70]);

    $component->assertSee('Tren progres historis');
});

test('analytics includes child task progress in its parent trend without double counting children', function () {
    $this->travelTo(now()->setTime(12, 0));
    $user = User::factory()->create();
    $parent = Task::factory()->for($user)->create(['due_at' => today()]);
    $child = Task::factory()->for($user)->create(['parent_task_id' => $parent->id]);
    $createdAt = today()->subDays(2)->setTime(12, 0);
    $parent->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();
    $child->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

    foreach ([
        [0, 40, today()->subDay()->setTime(9, 0)],
        [40, 80, today()->setTime(9, 0)],
    ] as [$from, $to, $eventAt]) {
        $event = $child->progressEvents()->create([
            'user_id' => $user->id,
            'from_progress' => $from,
            'to_progress' => $to,
        ]);
        $event->forceFill(['created_at' => $eventAt, 'updated_at' => $eventAt])->saveQuietly();
    }

    $this->actingAs($user);
    $component = Livewire::test('pages::analytics')->set('aggregationMode', 'count');

    expect($component->get('progressTrend')->pluck('progress')->all())->toBe([40, 80]);
});
