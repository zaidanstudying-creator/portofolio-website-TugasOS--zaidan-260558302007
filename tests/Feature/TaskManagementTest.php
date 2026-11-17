<?php

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;

test('a user can create a task and its checklist progress updates automatically', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::tasks')
        ->set('title', 'Laporan praktikum')
        ->set('priority', 'high')
        ->call('saveTask')
        ->assertHasNoErrors();

    $task = $user->tasks()->where('title', 'Laporan praktikum')->firstOrFail();

    Livewire::test('pages::tasks')
        ->set('newItemTitle', 'Analisis data')
        ->call('addItem', $task->id)
        ->assertHasNoErrors();

    $item = $task->items()->firstOrFail();

    Livewire::test('pages::tasks')
        ->call('toggleItem', $task->id, $item->id)
        ->assertHasNoErrors();

    expect($task->fresh()->computedProgress())->toBe(100)
        ->and($task->progressEvents()->latest()->value('to_progress'))->toBe(100);
});

test('subtask checklists can be added and removed while updating project progress', function () {
    $user = User::factory()->create();
    $project = Task::factory()->for($user)->create(['title' => 'Proyek basis data']);
    $subtask = Task::factory()->for($user)->create([
        'parent_task_id' => $project->id,
        'title' => 'Rancang skema',
    ]);
    $item = $subtask->items()->create(['title' => 'Buat ERD', 'position' => 0]);
    $this->actingAs($user);

    $component = Livewire::test('pages::tasks')
        ->assertSee('new-tree-item-'.$subtask->id)
        ->assertSee('Hapus checklist Buat ERD')
        ->set('newItemTitle', 'Validasi relasi')
        ->call('addItem', $subtask->id)
        ->assertHasNoErrors();

    $newItem = $subtask->items()->where('title', 'Validasi relasi')->firstOrFail();

    $component->call('toggleItem', $subtask->id, $item->id)->assertHasNoErrors();

    expect($project->fresh()->computedProgress())->toBe(50);

    $component->call('deleteItem', $subtask->id, $item->id)->assertHasNoErrors();

    expect($item->fresh())->toBeNull()
        ->and($newItem->fresh())->not->toBeNull()
        ->and($project->fresh()->computedProgress())->toBe(0)
        ->and($project->progressEvents()->latest()->value('to_progress'))->toBe(0);
});

test('task search finds matching subtasks and keeps their project context', function () {
    $user = User::factory()->create();
    $project = Task::factory()->for($user)->create(['title' => 'Proyek semester']);
    Task::factory()->for($user)->create([
        'parent_task_id' => $project->id,
        'title' => 'Analisis kebutuhan API',
    ]);
    Task::factory()->for($user)->create(['title' => 'Proyek lain']);
    $this->actingAs($user);

    Livewire::test('pages::tasks')
        ->set('search', 'Analisis kebutuhan')
        ->assertSee('Proyek semester')
        ->assertSee('Analisis kebutuhan API');
});

test('task search finds matching subtasks in archived projects', function () {
    $user = User::factory()->create();
    $project = Task::factory()->for($user)->create([
        'title' => 'Proyek lama',
        'archived_at' => now(),
    ]);
    Task::factory()->for($user)->create([
        'parent_task_id' => $project->id,
        'title' => 'Dokumentasi API',
        'archived_at' => now(),
    ]);
    $this->actingAs($user);

    Livewire::test('pages::tasks')
        ->set('showArchived', true)
        ->set('search', 'Dokumentasi API')
        ->assertSee('Proyek lama');
});

test('task reminders are created, updated, and cleared with the task form', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $reminderDate = now()->addDay()->startOfHour()->format('Y-m-d\\TH:i');

    Livewire::test('pages::tasks')
        ->set('title', 'Baca instruksi')
        ->set('remindAt', $reminderDate)
        ->set('reminderMessage', 'Bawa catatan')
        ->call('saveTask')
        ->assertHasNoErrors();

    $task = $user->tasks()->where('title', 'Baca instruksi')->firstOrFail();
    $reminder = $task->reminder()->firstOrFail();

    expect($reminder->user_id)->toBe($user->id)
        ->and($reminder->message)->toBe('Bawa catatan')
        ->and($reminder->remind_at->format('Y-m-d\\TH:i'))->toBe($reminderDate);

    Livewire::test('pages::tasks')
        ->call('editTask', $task->id)
        ->set('remindAt', '')
        ->call('saveTask')
        ->assertHasNoErrors();

    expect($reminder->fresh())->toBeNull();
});

test('canceling a task edit discards unsaved changes and clears the form', function () {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create(['title' => 'Judul asli']);
    $this->actingAs($user);

    Livewire::test('pages::tasks')
        ->call('editTask', $task->id)
        ->set('title', 'Perubahan belum disimpan')
        ->call('cancelEdit')
        ->assertHasNoErrors()
        ->assertSet('editingTaskId', null)
        ->assertSet('title', '')
        ->assertSet('description', '')
        ->assertDontSee('Batal edit');

    expect($task->fresh()->title)->toBe('Judul asli');
});

test('parent task progress uses weighted child progress without counting parent checklist', function () {
    $user = User::factory()->create();
    $parent = Task::factory()->for($user)->create();
    $firstChild = Task::factory()->for($user)->create(['parent_task_id' => $parent->id, 'weight' => 3]);
    $secondChild = Task::factory()->for($user)->create(['parent_task_id' => $parent->id, 'weight' => 1]);

    $firstChild->items()->create(['title' => 'Bagian pertama', 'position' => 0, 'is_completed' => true]);
    $secondChild->items()->create(['title' => 'Bagian kedua', 'position' => 0, 'is_completed' => false]);
    $parent->items()->create(['title' => 'Checklist induk', 'position' => 0, 'is_completed' => true]);

    expect($parent->computedProgress())->toBe(75)
        ->and($parent->computedProgress('equal'))->toBe(50);
});

test('nested project progress applies milestone weights without counting parent checklist twice', function () {
    $user = User::factory()->create();
    $project = Task::factory()->for($user)->create(['status' => 'todo']);
    $milestone = Task::factory()->for($user)->create([
        'parent_task_id' => $project->id,
        'status' => 'todo',
        'weight' => 2,
    ]);
    $completedSubtask = Task::factory()->for($user)->create([
        'parent_task_id' => $milestone->id,
        'status' => 'todo',
        'weight' => 2,
    ]);
    $unfinishedSubtask = Task::factory()->for($user)->create([
        'parent_task_id' => $milestone->id,
        'status' => 'todo',
        'weight' => 1,
    ]);
    Task::factory()->for($user)->create([
        'parent_task_id' => $project->id,
        'status' => 'done',
        'weight' => 1,
    ]);

    $completedSubtask->items()->create(['title' => 'Deliverable selesai', 'position' => 0, 'is_completed' => true]);
    $unfinishedSubtask->items()->create(['title' => 'Deliverable tertunda', 'position' => 0, 'is_completed' => false]);
    $project->items()->create(['title' => 'Checklist proyek', 'position' => 0, 'is_completed' => true]);

    expect($milestone->computedProgress())->toBe(67)
        ->and($project->computedProgress())->toBe(78);
});

test('nested project progress loads checklist data without a query per leaf task', function () {
    $user = User::factory()->create();
    $project = Task::factory()->for($user)->create();

    for ($milestoneIndex = 0; $milestoneIndex < 5; $milestoneIndex++) {
        $milestone = Task::factory()->for($user)->create(['parent_task_id' => $project->id]);

        for ($taskIndex = 0; $taskIndex < 5; $taskIndex++) {
            $task = Task::factory()->for($user)->create([
                'parent_task_id' => $milestone->id,
                'status' => 'todo',
            ]);
            $task->items()->create(['title' => 'Selesai', 'position' => 0, 'is_completed' => true]);
            $task->items()->create(['title' => 'Belum selesai', 'position' => 1, 'is_completed' => false]);
        }
    }

    $project = Task::query()->findOrFail($project->id);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            $queries[] = $query->sql;
        }
    });

    $progress = $project->computedProgress('equal');

    expect($progress)->toBe(50)
        ->and($queries)->toHaveCount(6);
});

test('changing a child task weight recalculates and records the parent progress', function () {
    $user = User::factory()->create();
    $parent = Task::factory()->for($user)->create(['title' => 'Proyek berbobot']);
    $completedChild = Task::factory()->for($user)->create([
        'parent_task_id' => $parent->id,
        'title' => 'Bagian selesai',
        'weight' => 3,
        'starts_at' => now()->startOfDay(),
        'due_at' => now()->addDay()->endOfDay(),
    ]);
    $unfinishedChild = Task::factory()->for($user)->create([
        'parent_task_id' => $parent->id,
        'title' => 'Bagian berjalan',
        'weight' => 1,
    ]);

    $completedChild->items()->create(['title' => 'Deliverable', 'position' => 0, 'is_completed' => true]);
    $unfinishedChild->items()->create(['title' => 'Deliverable', 'position' => 0, 'is_completed' => false]);

    expect($parent->computedProgress())->toBe(75);

    $this->actingAs($user);

    Livewire::test('pages::tasks')
        ->call('editTask', $completedChild->id)
        ->set('weight', '1')
        ->call('saveTask')
        ->assertHasNoErrors();

    expect($parent->fresh()->computedProgress())->toBe(50)
        ->and($parent->progressEvents()->latest()->value('from_progress'))->toBe(75)
        ->and($parent->progressEvents()->latest()->value('to_progress'))->toBe(50);
});

test('moving a task records progress changes for both project trees', function () {
    $user = User::factory()->create();
    $oldParent = Task::factory()->for($user)->create(['title' => 'Proyek lama', 'status' => 'todo']);
    $newParent = Task::factory()->for($user)->create(['title' => 'Proyek baru', 'status' => 'todo']);
    $child = Task::factory()->for($user)->create([
        'parent_task_id' => $oldParent->id,
        'title' => 'Milestone selesai',
    ]);
    $child->items()->create(['title' => 'Deliverable', 'position' => 0, 'is_completed' => true]);

    expect($oldParent->computedProgress())->toBe(100)
        ->and($newParent->computedProgress())->toBe(0);

    $this->actingAs($user);

    Livewire::test('pages::tasks')
        ->call('editTask', $child->id)
        ->set('parentTaskId', (string) $newParent->id)
        ->call('saveTask')
        ->assertHasNoErrors();

    expect($oldParent->fresh()->computedProgress())->toBe(0)
        ->and($newParent->fresh()->computedProgress())->toBe(100)
        ->and($oldParent->progressEvents()->latest()->value('to_progress'))->toBe(0)
        ->and($newParent->progressEvents()->latest()->value('to_progress'))->toBe(100);
});

test('task actions are scoped to the signed in user', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $task = Task::factory()->for($otherUser)->create();
    $this->actingAs($owner);

    Livewire::test('pages::tasks')
        ->call('deleteTask', $task->id)
        ->assertNotFound();

    expect($task->fresh())->not->toBeNull();
});

test('task status history is recorded and visible in analytics', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::tasks')
        ->set('title', 'Ujian basis data')
        ->set('dueAt', now()->addDays(2)->format('Y-m-d\\TH:i'))
        ->call('saveTask')
        ->assertHasNoErrors();

    $task = $user->tasks()->where('title', 'Ujian basis data')->firstOrFail();

    Livewire::test('pages::tasks')
        ->call('updateStatus', $task->id, 'in_progress')
        ->assertHasNoErrors();

    $events = $task->statusEvents()->oldest()->get();

    expect($events)->toHaveCount(2)
        ->and($events->first()->from_status)->toBeNull()
        ->and($events->first()->to_status)->toBe('todo')
        ->and($events->last()->from_status)->toBe('todo')
        ->and($events->last()->to_status)->toBe('in_progress');

    Livewire::test('pages::analytics')
        ->assertSee('Histori aktivitas')
        ->assertSee('Ujian basis data')
        ->assertSee('tugas dibuat')
        ->assertSee('in progress');
});

test('task status rolls back when writing status history fails', function () {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create(['status' => 'todo']);
    $this->actingAs($user);
    $failStatusHistoryWrite = true;

    DB::listen(function (QueryExecuted $query) use (&$failStatusHistoryWrite): void {
        if ($failStatusHistoryWrite
            && str_starts_with(strtolower(ltrim($query->sql)), 'insert')
            && str_contains($query->sql, 'task_status_events')) {
            $failStatusHistoryWrite = false;

            throw new RuntimeException('Simulated status history write failure.');
        }
    });

    expect(fn () => Livewire::test('pages::tasks')->call('updateStatus', $task->id, 'done'))
        ->toThrow(RuntimeException::class, 'Simulated status history write failure.');

    expect($task->fresh()->status)->toBe('todo')
        ->and($task->fresh()->completed_at)->toBeNull();
});

test('deleting a checklist item recalculates progress and records the change', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $task = Task::factory()->for($user)->create(['status' => 'in_progress']);
    $completedItem = $task->items()->create([
        'title' => 'Selesai',
        'position' => 0,
        'is_completed' => true,
        'completed_at' => now(),
    ]);
    $remainingItem = $task->items()->create([
        'title' => 'Masih berjalan',
        'position' => 1,
        'is_completed' => false,
    ]);

    expect($task->computedProgress())->toBe(50);

    Livewire::test('pages::tasks')
        ->call('deleteItem', $task->id, $completedItem->id)
        ->assertHasNoErrors()
        ->assertSee('Checklist dihapus dan progres diperbarui.');

    expect($completedItem->fresh())->toBeNull()
        ->and($remainingItem->fresh())->not->toBeNull()
        ->and($task->fresh()->computedProgress())->toBe(0)
        ->and($task->progressEvents()->latest()->value('from_progress'))->toBe(50)
        ->and($task->progressEvents()->latest()->value('to_progress'))->toBe(0);
});

test('a user cannot delete another users checklist item', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $task = Task::factory()->for($otherUser)->create();
    $item = $task->items()->create(['title' => 'Private checklist', 'position' => 0]);
    $this->actingAs($user);

    Livewire::test('pages::tasks')
        ->call('deleteItem', $task->id, $item->id)
        ->assertNotFound();

    expect($item->fresh())->not->toBeNull();
});

test('archiving a project hides its task tree and restoring it preserves task data', function () {
    $user = User::factory()->create();
    $parent = Task::factory()->for($user)->create(['title' => 'Proyek semester']);
    $child = Task::factory()->for($user)->create(['parent_task_id' => $parent->id, 'title' => 'Backend']);
    $grandchild = Task::factory()->for($user)->create(['parent_task_id' => $child->id, 'title' => 'API']);
    $item = $grandchild->items()->create(['title' => 'Buat endpoint', 'position' => 0]);
    $file = $grandchild->files()->create([
        'user_id' => $user->id,
        'kind' => 'final',
        'original_name' => 'hasil.pdf',
        'disk' => 'local',
        'path' => 'tasks/hasil.pdf',
        'mime_type' => 'application/pdf',
        'byte_size' => 10,
        'version' => 1,
        'is_final' => true,
    ]);
    $link = $grandchild->links()->create([
        'user_id' => $user->id,
        'label' => 'Repository',
        'url' => 'https://github.com/example/project',
        'kind' => 'repository',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::tasks')
        ->call('archiveTask', $parent->id)
        ->assertHasNoErrors()
        ->assertSee('diarsipkan');

    expect($parent->fresh()->archived_at)->not->toBeNull()
        ->and($child->fresh()->archived_at)->not->toBeNull()
        ->and($grandchild->fresh()->archived_at)->not->toBeNull()
        ->and($item->fresh())->not->toBeNull()
        ->and($file->fresh())->not->toBeNull()
        ->and($link->fresh())->not->toBeNull();

    $this->get(route('dashboard'))->assertOk()->assertDontSee('Proyek semester');
    Livewire::test('pages::calendar')->assertDontSee('Proyek semester');

    Livewire::test('pages::tasks')
        ->assertDontSee('Proyek semester')
        ->set('showArchived', true)
        ->assertSee('Proyek semester')
        ->call('restoreTask', $parent->id)
        ->assertHasNoErrors();

    expect($parent->fresh()->archived_at)->toBeNull()
        ->and($child->fresh()->archived_at)->toBeNull()
        ->and($grandchild->fresh()->archived_at)->toBeNull()
        ->and($parent->fresh()->children)->toHaveCount(1)
        ->and($file->fresh()->task_id)->toBe($grandchild->id)
        ->and($link->fresh()->task_id)->toBe($grandchild->id);
});

test('archiving a task is limited to the owner and top-level tasks', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherTask = Task::factory()->for($otherUser)->create();
    $parent = Task::factory()->for($user)->create();
    $child = Task::factory()->for($user)->create(['parent_task_id' => $parent->id]);
    $this->actingAs($user);

    Livewire::test('pages::tasks')->call('archiveTask', $otherTask->id)->assertNotFound();
    Livewire::test('pages::tasks')->call('archiveTask', $child->id)->assertNotFound();

    expect($otherTask->fresh()->archived_at)->toBeNull()
        ->and($parent->fresh()->archived_at)->toBeNull()
        ->and($child->fresh()->archived_at)->toBeNull();
});

test('deleting a task tree removes its private files after the database records are deleted', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create();
    $child = Task::factory()->for($user)->create(['parent_task_id' => $task->id]);
    $file = $child->files()->create([
        'user_id' => $user->id,
        'kind' => 'reference',
        'original_name' => 'reference.pdf',
        'disk' => 'local',
        'path' => 'task-files/'.$user->id.'/'.$child->id.'/reference.pdf',
        'mime_type' => 'application/pdf',
        'byte_size' => 1,
        'version' => 1,
    ]);
    Storage::disk('local')->put($file->path, 'private reference');
    $this->actingAs($user);

    Livewire::test('pages::tasks')
        ->call('deleteTask', $task->id)
        ->assertHasNoErrors();

    expect($task->fresh())->toBeNull()
        ->and($child->fresh())->toBeNull()
        ->and($file->fresh())->toBeNull();
    Storage::disk('local')->assertMissing($file->path);
});
