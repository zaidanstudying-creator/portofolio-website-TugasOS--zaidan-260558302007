<?php

use App\Models\Task;
use App\Models\TaskFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('a user can upload and download a private task file', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create();
    $this->actingAs($user);

    Livewire::test('pages::files')
        ->set('taskId', (string) $task->id)
        ->set('kind', 'final')
        ->set('upload', UploadedFile::fake()->create('final-report.pdf', 128, 'application/pdf'))
        ->call('saveFile')
        ->assertHasNoErrors();

    $file = TaskFile::firstOrFail();

    expect($file->is_final)->toBeTrue()
        ->and($file->version)->toBe(1)
        ->and($file->user_id)->toBe($user->id);

    Storage::disk('local')->assertExists($file->path);
    $this->get(route('files.download', $file))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('a failed storage write does not create a task file record', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create();
    $this->actingAs($user);

    $component = Livewire::test('pages::files')
        ->set('taskId', (string) $task->id)
        ->set('upload', UploadedFile::fake()->create('failed-upload.pdf', 128, 'application/pdf'));
    $temporaryDisk = Storage::disk('tmp-for-tests');
    $failedDisk = Mockery::mock(Storage::disk('local'))->makePartial();
    $failedDisk->shouldReceive('put')->once()->andReturnFalse();
    Storage::shouldReceive('disk')->with('tmp-for-tests')->andReturn($temporaryDisk);
    Storage::shouldReceive('disk')->with('local')->andReturn($failedDisk);

    $component->call('saveFile')
        ->assertHasErrors('upload')
        ->assertSee('File gagal disimpan.');

    expect($user->taskFiles()->count())->toBe(0);
});

test('uploaded final files receive increasing versions and replace the current final', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create();
    $this->actingAs($user);

    foreach (['final-v1.pdf', 'final-v2.pdf'] as $filename) {
        Livewire::test('pages::files')
            ->set('taskId', (string) $task->id)
            ->set('kind', 'final')
            ->set('upload', UploadedFile::fake()->create($filename, 128, 'application/pdf'))
            ->call('saveFile')
            ->assertHasNoErrors();
    }

    $versions = $task->files()->where('kind', 'final')->reorder('version')->get();

    expect($versions->pluck('version')->all())->toBe([1, 2])
        ->and($versions->where('is_final', true)->pluck('id')->all())->toBe([$versions->last()->id])
        ->and($versions->where('is_final', false))->toHaveCount(1);
});

test('file vault stores GitHub commit and pull request links with explicit evidence types', function () {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create();
    $this->actingAs($user);
    $component = Livewire::test('pages::files');

    $component
        ->set('linkTaskId', (string) $task->id)
        ->set('linkLabel', 'Commit: implement task calendar')
        ->set('linkUrl', 'https://github.com/example/course-project/commit/0123456789abcdef')
        ->set('linkKind', 'commit')
        ->call('saveLink')
        ->assertHasNoErrors();

    $component
        ->set('linkTaskId', (string) $task->id)
        ->set('linkLabel', 'Pull request: calendar support')
        ->set('linkUrl', 'https://github.com/example/course-project/pull/42')
        ->set('linkKind', 'pull_request')
        ->call('saveLink')
        ->assertHasNoErrors()
        ->assertSee('Commit')
        ->assertSee('Pull request');

    expect($task->links()->pluck('kind')->sort()->values()->all())->toBe(['commit', 'pull_request']);

    $component
        ->set('linkTaskId', (string) $task->id)
        ->set('linkLabel', 'Unsupported type')
        ->set('linkUrl', 'https://github.com/example/course-project')
        ->set('linkKind', 'unsupported')
        ->call('saveLink')
        ->assertHasErrors('linkKind');

    expect($task->links()->count())->toBe(2);
});

test('file vault rejects GitHub evidence labels that do not match their URLs', function () {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create();
    $this->actingAs($user);
    $invalidLinks = [
        ['commit', 'https://github.com/example/course-project/pull/42'],
        ['pull_request', 'https://github.com/example/course-project/commit/0123456'],
        ['repository', 'https://github.com/example/course-project/tree/main'],
        ['commit', 'https://attacker.example/example/course-project/commit/0123456'],
    ];

    foreach ($invalidLinks as [$kind, $url]) {
        Livewire::test('pages::files')
            ->set('linkTaskId', (string) $task->id)
            ->set('linkLabel', 'Evidence')
            ->set('linkUrl', $url)
            ->set('linkKind', $kind)
            ->call('saveLink')
            ->assertHasErrors('linkUrl');
    }

    expect($task->links()->count())->toBe(0);
});

test('file vault applies the account configured size and extension limits', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $user->settings = ['file_upload' => ['max_size_mb' => 1, 'extensions' => ['pdf']]];
    $user->save();
    $task = Task::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test('pages::files')
        ->set('taskId', (string) $task->id)
        ->set('upload', UploadedFile::fake()->create('too-large.pdf', 1025, 'application/pdf'))
        ->call('saveFile')
        ->assertHasErrors(['upload' => ['max']]);

    Livewire::actingAs($user)
        ->test('pages::files')
        ->set('taskId', (string) $task->id)
        ->set('upload', UploadedFile::fake()->create('not-allowed.png', 128, 'application/pdf'))
        ->call('saveFile')
        ->assertHasErrors('upload');

    expect($user->taskFiles()->count())->toBe(0);
});

test('private files cannot be downloaded by another account', function () {
    Storage::fake('local');
    $owner = User::factory()->create();
    $task = Task::factory()->for($owner)->create();
    $file = $task->files()->create([
        'user_id' => $owner->id,
        'kind' => 'reference',
        'original_name' => 'notes.pdf',
        'disk' => 'local',
        'path' => 'task-files/private.pdf',
        'mime_type' => 'application/pdf',
        'byte_size' => 1,
        'version' => 1,
    ]);
    Storage::disk('local')->put($file->path, 'x');

    $this->actingAs(User::factory()->create())
        ->get(route('files.download', $file))
        ->assertNotFound();
});

test('manual submission evidence is recorded without claiming LMS verification', function () {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create();
    $file = $task->files()->create([
        'user_id' => $user->id,
        'kind' => 'proof',
        'original_name' => 'receipt.pdf',
        'disk' => 'local',
        'path' => 'task-files/receipt.pdf',
        'mime_type' => 'application/pdf',
        'byte_size' => 1,
        'version' => 1,
    ]);
    $this->actingAs($user);

    Livewire::test('pages::submissions')
        ->set('taskId', (string) $task->id)
        ->set('proofFileId', (string) $file->id)
        ->set('confirmationReference', 'receipt-123')
        ->call('recordManualSubmission')
        ->assertHasNoErrors();

    $submission = $user->taskSubmissions()->firstOrFail();

    expect($submission->status)->toBe('manual_recorded')
        ->and($submission->assessment_status)->toBe('pending')
        ->and($submission->confirmation_method)->toBe('manual')
        ->and($submission->proof_file_id)->toBe($file->id)
        ->and($submission->attempts()->firstOrFail()->response_payload['lms_verified'])->toBeFalse()
        ->and($submission->assessmentEvents()->firstOrFail()->event_type)->toBe('initial')
        ->and($submission->assessmentEvents()->firstOrFail()->to_status)->toBe('pending');
});

test('a file used as submission evidence cannot be deleted from the vault', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create();
    $file = $task->files()->create([
        'user_id' => $user->id,
        'kind' => 'proof',
        'original_name' => 'receipt.pdf',
        'disk' => 'local',
        'path' => 'task-files/receipt.pdf',
        'mime_type' => 'application/pdf',
        'byte_size' => 1,
        'version' => 1,
    ]);
    Storage::disk('local')->put($file->path, 'proof');
    $submission = $user->taskSubmissions()->create([
        'task_id' => $task->id,
        'proof_file_id' => $file->id,
        'platform' => 'manual',
        'status' => 'manual_recorded',
        'confirmation_method' => 'manual',
    ]);
    $this->actingAs($user);

    Livewire::test('pages::files')
        ->call('deleteFile', $file->id)
        ->assertHasErrors('file')
        ->assertSee('dipakai sebagai bukti pengumpulan');

    expect($file->fresh())->not->toBeNull()
        ->and($submission->fresh()->proof_file_id)->toBe($file->id);
    Storage::disk('local')->assertExists($file->path);
});

test('manual submissions reject files that are not final work or submission proof', function () {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create();
    $instructionFile = $task->files()->create([
        'user_id' => $user->id,
        'kind' => 'instruction',
        'original_name' => 'instructions.pdf',
        'disk' => 'local',
        'path' => 'task-files/instructions.pdf',
        'mime_type' => 'application/pdf',
        'byte_size' => 1,
        'version' => 1,
    ]);
    $this->actingAs($user);

    Livewire::test('pages::submissions')
        ->set('taskId', (string) $task->id)
        ->set('proofFileId', (string) $instructionFile->id)
        ->call('recordManualSubmission')
        ->assertHasErrors('proofFileId');

    expect($user->taskSubmissions()->count())->toBe(0);
});

test('new manual submissions cannot be recorded against an archived task', function () {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create(['archived_at' => now()]);
    $this->actingAs($user);

    Livewire::test('pages::submissions')
        ->set('taskId', (string) $task->id)
        ->call('recordManualSubmission')
        ->assertHasErrors('taskId');

    expect($user->taskSubmissions()->count())->toBe(0);
});

test('assessment status changes independently from the submission status', function () {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create(['title' => 'Tugas teori']);
    $submission = $user->taskSubmissions()->create([
        'task_id' => $task->id,
        'platform' => 'manual',
        'status' => 'manual_recorded',
        'assessment_status' => 'pending',
        'confirmation_method' => 'manual',
    ]);
    $this->actingAs($user);

    Livewire::test('pages::submissions')
        ->call('updateAssessmentStatus', $submission->id, 'revision_required')
        ->assertHasNoErrors()
        ->assertSee('Perlu revisi')
        ->assertSee('Riwayat penilaian');

    expect($submission->fresh()->status)->toBe('manual_recorded')
        ->and($submission->fresh()->assessment_status)->toBe('revision_required')
        ->and($submission->fresh()->assessed_at)->not->toBeNull();

    Livewire::test('pages::submissions')
        ->call('updateAssessmentStatus', $submission->id, 'pending')
        ->assertHasNoErrors();

    Livewire::test('pages::submissions')
        ->call('updateAssessmentStatus', $submission->id, 'pending')
        ->assertHasNoErrors();

    expect($submission->fresh()->status)->toBe('manual_recorded')
        ->and($submission->fresh()->assessment_status)->toBe('pending')
        ->and($submission->fresh()->assessed_at)->toBeNull()
        ->and($submission->assessmentEvents()->count())->toBe(3)
        ->and($submission->assessmentEvents()->get()->map(fn ($event): array => [
            $event->from_status,
            $event->to_status,
        ])->all())->toBe([
            [null, 'pending'],
            ['pending', 'revision_required'],
            ['revision_required', 'pending'],
        ]);
});

test('a user cannot change another users submission assessment', function () {
    $owner = User::factory()->create();
    $task = Task::factory()->for($owner)->create();
    $submission = $owner->taskSubmissions()->create([
        'task_id' => $task->id,
        'platform' => 'manual',
        'status' => 'manual_recorded',
        'assessment_status' => 'pending',
        'confirmation_method' => 'manual',
    ]);
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::submissions')
        ->call('updateAssessmentStatus', $submission->id, 'graded')
        ->assertNotFound();

    expect($submission->fresh()->assessment_status)->toBe('pending');
});
