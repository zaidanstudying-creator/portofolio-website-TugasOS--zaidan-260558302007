<?php

use App\Jobs\ProcessAiDocumentUnderstanding;
use App\Models\AiRequest;
use App\Models\Task;
use App\Models\TaskFile;
use App\Models\User;
use App\Services\Ai\AiAssistantService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function documentUnderstandingFixtureResult(?string $deadline = 'Jumat, 14 November 2026 pukul 23.59'): array
{
    return [
        'title' => 'Laporan observasi lapangan',
        'summary' => 'Susun laporan berdasarkan hasil observasi.',
        'deliverables' => ['Laporan tertulis', 'Lampiran foto'],
        'deadline_text' => $deadline,
        'requirements' => ['Tulis latar belakang', 'Cantumkan hasil observasi', 'Tambahkan kesimpulan'],
        'submission_format' => 'Satu dokumen PDF dengan nama file NIM_nama.pdf',
    ];
}

function createPrivatePdfForUser(User $user, string $filename = 'instruksi.pdf'): TaskFile
{
    $task = Task::factory()->for($user)->create();
    $path = 'task-files/'.$user->id.'/'.$task->id.'/'.$filename;
    $contents = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    Storage::disk('local')->put($path, $contents);

    return TaskFile::query()->create([
        'task_id' => $task->id,
        'user_id' => $user->id,
        'kind' => 'instruction',
        'original_name' => $filename,
        'disk' => 'local',
        'path' => $path,
        'mime_type' => 'application/pdf',
        'byte_size' => strlen($contents),
        'version' => 1,
        'sha256' => hash('sha256', $contents),
    ]);
}

function fakeDocumentUnderstandingResponse(array $result): array
{
    return [
        'status' => 'completed',
        'output' => [[
            'type' => 'message',
            'content' => [[
                'type' => 'output_text',
                'text' => json_encode($result, JSON_THROW_ON_ERROR),
            ]],
        ]],
    ];
}

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('services.ai.provider', 'openai');
    config()->set('services.ai.api_key', 'test-api-key');
    config()->set('services.ai.model', 'test-model');
});

test('document content is not sent without explicit consent', function () {
    Http::preventStrayRequests();

    $user = User::factory()->create();
    $file = createPrivatePdfForUser($user);

    expect(fn () => app(AiAssistantService::class)->understandTaskDocument($user, $file, false))
        ->toThrow(RuntimeException::class);

    expect(AiRequest::query()->count())->toBe(0);
});

test('selected private PDF becomes an extraction proposal without creating a task', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.openai.com/v1/responses' => Http::response(fakeDocumentUnderstandingResponse(documentUnderstandingFixtureResult())),
    ]);

    $user = User::factory()->create();
    $file = createPrivatePdfForUser($user, 'NIM-12345-Instruksi-Pribadi.pdf');
    $contents = Storage::disk('local')->get($file->path);

    $proposal = app(AiAssistantService::class)->understandTaskDocument($user, $file, true);

    expect($proposal->purpose)->toBe('document_understanding')
        ->and($proposal->status)->toBe('proposed')
        ->and($proposal->input_hash)->toBe(hash('sha256', $contents))
        ->and($proposal->result['requirements'])->toHaveCount(3)
        ->and($proposal->consented_at)->not->toBeNull()
        ->and(Task::query()->count())->toBe(1);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/responses'
        && $request->data()['store'] === false
        && $request->data()['input'][0]['content'][0]['type'] === 'input_file'
        && $request->data()['input'][0]['content'][0]['filename'] === 'task-instructions.pdf'
        && ! str_contains(json_encode($request->data(), JSON_THROW_ON_ERROR), $file->original_name)
        && str_starts_with($request->data()['input'][0]['content'][0]['file_data'], 'data:application/pdf;base64,')
        && base64_decode(substr($request->data()['input'][0]['content'][0]['file_data'], strlen('data:application/pdf;base64,'))) === $contents);
});

test('document deadline is only written after the user confirms a calendar value', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.openai.com/v1/responses' => Http::response(fakeDocumentUnderstandingResponse(documentUnderstandingFixtureResult())),
    ]);

    $user = User::factory()->create();
    $file = createPrivatePdfForUser($user);
    $proposal = app(AiAssistantService::class)->understandTaskDocument($user, $file, true);

    expect(Task::query()->count())->toBe(1);

    $task = app(AiAssistantService::class)->acceptDocumentUnderstanding($user, $proposal, '2026-11-14T23:59');

    expect($task->title)->toBe('Laporan observasi lapangan')
        ->and($task->due_at->format('Y-m-d H:i'))->toBe('2026-11-14 23:59')
        ->and($task->items)->toHaveCount(3)
        ->and($task->taskNotes->first()->body)->toContain('Jumat, 14 November 2026 pukul 23.59')
        ->and($proposal->fresh()->status)->toBe('accepted')
        ->and($proposal->fresh()->task_id)->toBe($task->id);

    expect(fn () => app(AiAssistantService::class)->acceptDocumentUnderstanding($user, $proposal, null))
        ->toThrow(RuntimeException::class);

    expect(Task::query()->count())->toBe(2);
});

test('document analysis only allows files owned by the current user', function () {
    Http::preventStrayRequests();

    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $file = createPrivatePdfForUser($otherUser);

    expect(fn () => app(AiAssistantService::class)->understandTaskDocument($owner, $file, true))
        ->toThrow(ModelNotFoundException::class);

    expect($owner->aiRequests()->count())->toBe(0);
});

test('unsupported file types are rejected before contacting the provider', function () {
    Http::preventStrayRequests();

    $user = User::factory()->create();
    $file = createPrivatePdfForUser($user, 'archive.zip');

    expect(fn () => app(AiAssistantService::class)->understandTaskDocument($user, $file, true))
        ->toThrow(RuntimeException::class);

    expect($user->aiRequests()->count())->toBe(0);
});

test('document extraction queues an explicitly consented request without sending the file in the page request', function () {
    Http::preventStrayRequests();
    Queue::fake([ProcessAiDocumentUnderstanding::class]);

    $user = User::factory()->create();
    $file = createPrivatePdfForUser($user);

    Livewire\Livewire::actingAs($user)
        ->test('pages::assistant')
        ->set('documentFileId', (string) $file->id)
        ->set('hasDocumentConsent', true)
        ->call('analyzeDocument')
        ->assertHasNoErrors()
        ->assertSee('Dokumen masuk antrean analisis');

    $request = $user->aiRequests()->sole();

    expect($request->status)->toBe('queued')
        ->and($request->task_file_id)->toBe($file->id)
        ->and($request->consented_at)->not->toBeNull();

    Queue::assertPushed(ProcessAiDocumentUnderstanding::class, fn (ProcessAiDocumentUnderstanding $job): bool => $job->aiRequestId === $request->id);
});

test('queued document extraction records a proposal when the worker completes', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.openai.com/v1/responses' => Http::response(fakeDocumentUnderstandingResponse(documentUnderstandingFixtureResult())),
    ]);

    $user = User::factory()->create();
    $file = createPrivatePdfForUser($user, 'NIM-67890-Rahasia.pdf');
    $request = app(AiAssistantService::class)->queueTaskDocumentUnderstanding($user, $file, true);

    app(ProcessAiDocumentUnderstanding::class, ['aiRequestId' => $request->id])->handle(app(AiAssistantService::class));

    expect($request->fresh()->status)->toBe('proposed')
        ->and($request->fresh()->result['title'])->toBe('Laporan observasi lapangan')
        ->and(Task::query()->count())->toBe(1);

    Http::assertSent(fn (Request $sentRequest): bool => $sentRequest->data()['input'][0]['content'][0]['filename'] === 'task-instructions.pdf'
        && ! str_contains(json_encode($sentRequest->data(), JSON_THROW_ON_ERROR), $file->original_name));
});

test('terminal document analysis failure is visible in request history', function () {
    Queue::fake([ProcessAiDocumentUnderstanding::class]);

    $user = User::factory()->create();
    $file = createPrivatePdfForUser($user);
    $request = app(AiAssistantService::class)->queueTaskDocumentUnderstanding($user, $file, true);

    (new ProcessAiDocumentUnderstanding($request->id))->failed(null);

    expect($request->fresh()->status)->toBe('failed')
        ->and($request->fresh()->failure_code)->toBe('provider_error');
});

test('queued document analysis fails clearly if its source file is deleted before processing', function () {
    Queue::fake([ProcessAiDocumentUnderstanding::class]);

    $user = User::factory()->create();
    $file = createPrivatePdfForUser($user);
    $request = app(AiAssistantService::class)->queueTaskDocumentUnderstanding($user, $file, true);
    $file->delete();

    (new ProcessAiDocumentUnderstanding($request->id))->handle(app(AiAssistantService::class));

    expect($request->fresh()->status)->toBe('failed')
        ->and($request->fresh()->failure_code)->toBe('source_file_unavailable')
        ->and($request->fresh()->completed_at)->not->toBeNull();
});

test('queued document analysis does not send file contents changed after consent', function () {
    Http::preventStrayRequests();
    Queue::fake([ProcessAiDocumentUnderstanding::class]);

    $user = User::factory()->create();
    $file = createPrivatePdfForUser($user);
    $request = app(AiAssistantService::class)->queueTaskDocumentUnderstanding($user, $file, true);
    $changedContents = "%PDF-1.4\nchanged after consent\n%%EOF\n";
    Storage::disk('local')->put($file->path, $changedContents);

    expect($request->fresh()->input_hash)->not->toBe(hash('sha256', Storage::disk('local')->get($file->path)));

    (new ProcessAiDocumentUnderstanding($request->id))->handle(app(AiAssistantService::class));

    expect($request->fresh()->status)->toBe('failed')
        ->and($request->fresh()->failure_code)->toBe('source_file_changed')
        ->and($request->fresh()->completed_at)->not->toBeNull();

    Http::assertNothingSent();
});

test('AI page explains document consent and supported extraction', function () {
    config()->set('services.ai.api_key', null);
    config()->set('services.ai.model', null);

    $this->actingAs(User::factory()->create())
        ->get(route('assistant'))
        ->assertOk()
        ->assertSee('Baca instruksi dari File Vault')
        ->assertSee('isi file yang dipilih')
        ->assertSee('AI tidak menyetel deadline kalender');
});
