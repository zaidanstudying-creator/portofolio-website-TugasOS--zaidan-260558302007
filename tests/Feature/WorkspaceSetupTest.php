<?php

use App\Models\Category;
use App\Models\Semester;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskFilePolicy;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('stores foundation records under the signed-in user', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::workspace-settings')
        ->set('semesterName', '2026/2027 Ganjil')
        ->set('semesterStartsAt', '2026-08-01')
        ->set('semesterEndsAt', '2026-12-20')
        ->set('semesterIsActive', true)
        ->call('saveSemester')
        ->set('courseName', 'Rekayasa Perangkat Lunak')
        ->set('courseCode', 'IF-302')
        ->set('courseSemesterId', $user->semesters()->value('id'))
        ->call('saveCourse')
        ->set('categoryName', 'Proyek')
        ->call('saveCategory')
        ->set('assignerName', 'Rani Putri')
        ->set('assignerTitle', 'Dosen')
        ->set('assignerRoles', ['Dosen', 'Pembimbing'])
        ->call('saveAssigner');

    $this->assertDatabaseHas('semesters', ['user_id' => $user->id, 'name' => '2026/2027 Ganjil', 'is_active' => true]);
    $this->assertDatabaseHas('courses', ['user_id' => $user->id, 'name' => 'Rekayasa Perangkat Lunak', 'code' => 'IF-302']);
    $this->assertDatabaseHas('categories', ['user_id' => $user->id, 'name' => 'Proyek']);
    $this->assertDatabaseHas('assigner_roles', ['name' => 'Dosen']);
    $this->assertDatabaseHas('assigner_roles', ['name' => 'Pembimbing']);
});

it('rejects a semester owned by another user when creating a course', function () {
    $user = User::factory()->create();
    $otherUserSemester = Semester::factory()->for(User::factory()->create())->create();

    Livewire::actingAs($user)
        ->test('pages::workspace-settings')
        ->set('courseName', 'Mata Kuliah Asing')
        ->set('courseSemesterId', $otherUserSemester->id)
        ->call('saveCourse')
        ->assertHasErrors(['courseSemesterId' => ['exists']]);

    $this->assertDatabaseMissing('courses', ['user_id' => $user->id, 'name' => 'Mata Kuliah Asing']);
});

it('does not allow deleting another users foundation data', function () {
    $user = User::factory()->create();
    $otherUserCategory = Category::factory()->for(User::factory()->create())->create();

    Livewire::actingAs($user)
        ->test('pages::workspace-settings')
        ->call('deleteRecord', 'category', $otherUserCategory->id)
        ->assertNotFound();

    $this->assertModelExists($otherUserCategory);
});

it('requires a semester end date on or after its start date', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::workspace-settings')
        ->set('semesterName', 'Semester tidak valid')
        ->set('semesterStartsAt', '2026-12-20')
        ->set('semesterEndsAt', '2026-08-01')
        ->call('saveSemester')
        ->assertHasErrors(['semesterEndsAt' => ['after_or_equal']]);
});

it('stores file vault limits and formats in the signed-in users settings', function () {
    $user = User::factory()->create();
    $maxFileSizeMb = app(TaskFilePolicy::class)->serverMaxSizeMb();

    Livewire::actingAs($user)
        ->test('pages::workspace-settings')
        ->set('maxFileSizeMb', (string) $maxFileSizeMb)
        ->set('allowedFileExtensions', ['pdf', 'docx'])
        ->call('saveFileSettings')
        ->assertHasNoErrors();

    expect($user->fresh()->settings['file_upload'])->toBe([
        'max_size_mb' => $maxFileSizeMb,
        'extensions' => ['pdf', 'docx'],
    ]);

    expect(app(TaskFilePolicy::class)->forUser($user->fresh())['max_size_kb'])
        ->toBeLessThanOrEqual($maxFileSizeMb * 1024)
        ->toBeGreaterThan(0);
});

it('stores the progress aggregation method and records affected project progress', function () {
    $user = User::factory()->create(['settings' => ['theme' => 'light']]);
    $project = Task::factory()->for($user)->create(['title' => 'Proyek semester']);
    $largeMilestone = Task::factory()->for($user)->create([
        'parent_task_id' => $project->id,
        'weight' => 3,
    ]);
    $smallMilestone = Task::factory()->for($user)->create([
        'parent_task_id' => $project->id,
        'weight' => 1,
    ]);
    $largeMilestone->items()->create(['title' => 'Deliverable selesai', 'position' => 0, 'is_completed' => true]);
    $smallMilestone->items()->create(['title' => 'Deliverable tertunda', 'position' => 0, 'is_completed' => false]);

    expect($project->computedProgress())->toBe(75);

    Livewire::actingAs($user)
        ->test('pages::workspace-settings')
        ->set('progressAggregation', 'equal')
        ->call('saveProgressAggregation')
        ->assertHasNoErrors();

    expect($user->fresh()->settings)->toBe([
        'theme' => 'light',
        'progress_aggregation' => 'equal',
    ])
        ->and($project->fresh()->computedProgress())->toBe(50)
        ->and($project->progressEvents()->latest()->value('from_progress'))->toBe(75)
        ->and($project->progressEvents()->latest()->value('to_progress'))->toBe(50);
});

it('stores AI credentials encrypted and keeps them out of Livewire output', function () {
    $user = User::factory()->create();
    $secret = 'sk-private-user-test-key';

    $component = Livewire::actingAs($user)
        ->test('pages::workspace-settings')
        ->set('aiModel', 'gpt-4.1-mini')
        ->set('aiApiKey', $secret)
        ->call('saveAiSettings')
        ->assertHasNoErrors()
        ->assertSet('aiApiKey', '')
        ->assertDontSee($secret);

    $storedValue = DB::table('users')->where('id', $user->id)->value('ai_api_key');
    $savedUser = $user->fresh();

    expect($storedValue)->not->toBe($secret)
        ->and($savedUser->ai_api_key)->toBe($secret)
        ->and($savedUser->ai_model)->toBe('gpt-4.1-mini')
        ->and($savedUser->toArray())->not->toHaveKey('ai_api_key')
        ->and($component->html())->not->toContain($secret);
});

it('clears the stored AI credential without changing the selected model', function () {
    $user = User::factory()->create();
    $user->forceFill(['ai_api_key' => 'sk-private-user-test-key', 'ai_model' => 'gpt-custom'])->save();

    Livewire::actingAs($user)
        ->test('pages::workspace-settings')
        ->call('removeAiApiKey')
        ->assertHasNoErrors();

    expect($user->fresh()->ai_api_key)->toBeNull()
        ->and($user->fresh()->ai_model)->toBe('gpt-custom');
});

it('rejects unsupported project progress aggregation methods', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::workspace-settings')
        ->set('progressAggregation', 'unweighted-by-magic')
        ->call('saveProgressAggregation')
        ->assertHasErrors(['progressAggregation' => ['in']]);

    expect($user->fresh()->settings)->toBeNull();
});

it('rejects file formats outside the supported vault allow list', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::workspace-settings')
        ->set('maxFileSizeMb', '1')
        ->set('allowedFileExtensions', ['exe'])
        ->call('saveFileSettings')
        ->assertHasErrors(['allowedFileExtensions.0' => ['in']]);

    expect($user->fresh()->settings['file_upload'] ?? null)->toBeNull();
});
