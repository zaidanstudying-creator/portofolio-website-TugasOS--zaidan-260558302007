<?php

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use ZipArchive;

test('an account can export its records and private files without integration credentials', function () {
    Storage::fake('local');
    $user = User::factory()->create(['settings' => ['deadline_urgency' => ['critical_hours' => 12]]]);
    $user->forceFill(['ai_api_key' => 'must-not-be-exported-ai-key'])->save();
    $task = Task::factory()->for($user)->create(['title' => 'Laporan pribadi']);
    $task->statusEvents()->create([
        'user_id' => $user->id,
        'from_status' => null,
        'to_status' => 'todo',
    ]);
    $submission = $user->taskSubmissions()->create([
        'task_id' => $task->id,
        'platform' => 'manual',
        'status' => 'manual_recorded',
        'confirmation_method' => 'manual',
    ]);
    Livewire::actingAs($user)
        ->test('pages::submissions')
        ->call('updateAssessmentStatus', $submission->id, 'graded')
        ->assertHasNoErrors();
    $path = 'task-files/'.$user->id.'/'.$task->id.'/hasil.pdf';
    Storage::disk('local')->put($path, 'private attachment contents');
    $file = $task->files()->create([
        'user_id' => $user->id,
        'kind' => 'final',
        'original_name' => 'hasil.pdf',
        'disk' => 'local',
        'path' => $path,
        'mime_type' => 'application/pdf',
        'byte_size' => 27,
        'version' => 1,
        'is_final' => true,
        'sha256' => hash('sha256', 'private attachment contents'),
    ]);
    $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'must-not-be-exported-access-token',
        'refresh_token' => 'must-not-be-exported-refresh-token',
        'status' => 'connected',
        'settings' => ['calendar_id' => 'primary'],
    ]);
    $otherUser = User::factory()->create();
    Task::factory()->for($otherUser)->create(['title' => 'Tugas akun lain']);

    $response = $this->actingAs($user)
        ->get(route('backup.download'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/zip')
        ->assertHeader('Cache-Control', 'no-store, private');

    $archivePath = $response->baseResponse->getFile()->getPathname();
    $archive = new ZipArchive;
    expect($archive->open($archivePath))->toBeTrue();

    $backup = json_decode($archive->getFromName('backup.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($backup['format'])->toBe('tugasos-backup-v1')
        ->and($backup['user']['email'])->toBe($user->email)
        ->and($backup['tasks'])->toHaveCount(1)
        ->and($backup['tasks'][0]['title'])->toBe('Laporan pribadi')
        ->and($backup['tasks'][0]['status_events'])->toHaveCount(1)
        ->and($backup['tasks'][0]['submissions'][0]['assessment_events'])->toHaveCount(2)
        ->and($backup['task_files'][0]['id'])->toBe($file->id)
        ->and($backup['task_files'][0]['archive_path'])->toBe('files/task-'.$task->id.'/file-'.$file->id)
        ->and($backup)->not->toHaveKey('password')
        ->and(json_encode($backup, JSON_THROW_ON_ERROR))->not->toContain('must-not-be-exported-access-token')
        ->and(json_encode($backup, JSON_THROW_ON_ERROR))->not->toContain('must-not-be-exported-refresh-token')
        ->and(json_encode($backup, JSON_THROW_ON_ERROR))->not->toContain('must-not-be-exported-ai-key')
        ->and(json_encode($backup, JSON_THROW_ON_ERROR))->not->toContain('Tugas akun lain')
        ->and($archive->getFromName('files/task-'.$task->id.'/file-'.$file->id))->toBe('private attachment contents');

    $archive->close();
    @unlink($archivePath);
});

test('backup export requires authentication', function () {
    $this->get(route('backup.download'))->assertRedirect(route('login'));
});
