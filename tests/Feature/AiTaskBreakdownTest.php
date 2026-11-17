<?php

use App\Exceptions\Services\Ai\AiRateLimitException;
use App\Models\AiRequest;
use App\Models\Task;
use App\Models\User;
use App\Services\Ai\AiAssistantService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

function taskBreakdownResult(): array
{
    return [
        'title' => 'Bangun aplikasi inventaris',
        'summary' => 'Aplikasi inventaris dengan dokumentasi teknis.',
        'deliverables' => ['Aplikasi berjalan', 'Dokumentasi'],
        'checklist' => ['Rancang skema data', 'Buat API', 'Tulis pengujian'],
        'milestones' => ['Perancangan', 'Implementasi', 'Evaluasi'],
        'suggested_sequence' => ['Rancang skema data', 'Buat API', 'Tulis pengujian'],
        'estimated_minutes' => 480,
        'schedule_suggestions' => ['Bagi pekerjaan menjadi sesi fokus 60–90 menit.'],
    ];
}

function fakeTaskBreakdownResponse(array $result): array
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
    config()->set('services.ai.provider', 'openai');
    config()->set('services.ai.api_key', 'test-api-key');
    config()->set('services.ai.model', 'test-model');
});

test('task instructions are not sent without explicit consent', function () {
    Http::preventStrayRequests();

    expect(fn () => app(AiAssistantService::class)->proposeTaskBreakdown(
        User::factory()->create(),
        'Create a detailed inventory application with tests.',
        false,
    ))->toThrow(RuntimeException::class);

    expect(AiRequest::query()->count())->toBe(0);
});

test('AI task breakdown is stored as a proposal without creating a task', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.openai.com/v1/responses' => Http::response(fakeTaskBreakdownResponse(taskBreakdownResult())),
    ]);

    $user = User::factory()->create();
    $instruction = 'Create an inventory application with a database design, API, user interface and tests.';

    $proposal = app(AiAssistantService::class)->proposeTaskBreakdown($user, $instruction, true);

    expect($proposal->status)->toBe('proposed')
        ->and($proposal->result['checklist'])->toHaveCount(3)
        ->and($proposal->input_hash)->toBe(hash('sha256', $instruction))
        ->and($proposal->consented_at)->not->toBeNull()
        ->and(Task::query()->count())->toBe(0);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/responses'
        && $request->hasHeader('Authorization', 'Bearer test-api-key')
        && $request->data()['store'] === false
        && $request->data()['input'] === $instruction
        && $request->data()['text']['format']['strict'] === true);
});

test('AI requests prefer the signed-in users encrypted provider credentials', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.openai.com/v1/responses' => Http::response(fakeTaskBreakdownResponse(taskBreakdownResult())),
    ]);

    $user = User::factory()->create();
    $user->forceFill([
        'ai_provider' => 'openai',
        'ai_model' => 'user-selected-model',
        'ai_api_key' => 'sk-private-user-key',
    ])->save();

    $proposal = app(AiAssistantService::class)->proposeTaskBreakdown($user, 'A project instruction.', true);

    expect($proposal->model)->toBe('user-selected-model');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer sk-private-user-key')
        && $request->data()['model'] === 'user-selected-model');
});

test('accepting a proposal creates a task and checklist only after approval', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.openai.com/v1/responses' => Http::response(fakeTaskBreakdownResponse(taskBreakdownResult())),
    ]);

    $user = User::factory()->create();
    $proposal = app(AiAssistantService::class)->proposeTaskBreakdown(
        $user,
        'Create an inventory application with a database design, API, user interface and tests.',
        true,
    );

    expect(Task::query()->count())->toBe(0);

    $task = app(AiAssistantService::class)->acceptTaskBreakdown($user, $proposal);

    expect($task->title)->toBe('Bangun aplikasi inventaris')
        ->and($task->due_at)->toBeNull()
        ->and($task->estimated_minutes)->toBe(480)
        ->and($task->items)->toHaveCount(3)
        ->and($task->taskNotes)->toHaveCount(1)
        ->and($task->statusEvents()->first()->from_status)->toBeNull()
        ->and($task->statusEvents()->first()->to_status)->toBe('todo')
        ->and($proposal->fresh()->status)->toBe('accepted')
        ->and($proposal->fresh()->task_id)->toBe($task->id);

    expect(fn () => app(AiAssistantService::class)->acceptTaskBreakdown($user, $proposal))
        ->toThrow(RuntimeException::class);

    expect(Task::query()->count())->toBe(1);
});

test('provider failure is recorded and never creates a task', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.openai.com/v1/responses' => Http::failedConnection(),
    ]);

    $user = User::factory()->create();

    expect(fn () => app(AiAssistantService::class)->proposeTaskBreakdown(
        $user,
        'Create an inventory application with a database design, API, user interface and tests.',
        true,
    ))->toThrow(ConnectionException::class);

    expect($user->aiRequests()->firstOrFail()->status)->toBe('failed')
        ->and($user->aiRequests()->firstOrFail()->failure_code)->toBe('provider_error')
        ->and(Task::query()->count())->toBe(0);
});

test('AI task breakdown applies a per-user usage limit', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.openai.com/v1/responses' => Http::response(fakeTaskBreakdownResponse(taskBreakdownResult())),
    ]);

    $user = User::factory()->create();
    $key = 'ai:requests:user:'.$user->id;
    RateLimiter::clear($key);

    foreach (range(1, 10) as $attempt) {
        app(AiAssistantService::class)->proposeTaskBreakdown(
            $user,
            'Create an inventory application with a database design, API, user interface and tests.',
            true,
        );
    }

    expect(fn () => app(AiAssistantService::class)->proposeTaskBreakdown(
        $user,
        'Create an inventory application with a database design, API, user interface and tests.',
        true,
    ))->toThrow(AiRateLimitException::class);

    expect($user->aiRequests()->count())->toBe(10)
        ->and(Task::query()->count())->toBe(0);

    Http::assertSentCount(10);
});

test('priority advice requires consent and does not mutate task fields', function () {
    Http::preventStrayRequests();

    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create([
        'title' => 'Submit research report',
        'status' => 'in_progress',
        'priority' => 'high',
        'due_at' => now()->addDay(),
        'estimated_minutes' => 180,
    ]);

    expect(fn () => app(AiAssistantService::class)->recommendTaskPriority($user, false))
        ->toThrow(RuntimeException::class);

    expect($user->aiRequests()->count())->toBe(0);

    Http::fake([
        'api.openai.com/v1/responses' => Http::response(fakeTaskBreakdownResponse([
            'recommendations' => [[
                'task_id' => $task->id,
                'rank' => 1,
                'reason' => 'Tenggatnya dekat dan tugas masih dikerjakan.',
                'next_action' => 'Selesaikan bagian hasil penelitian.',
            ]],
        ])),
    ]);

    $advice = app(AiAssistantService::class)->recommendTaskPriority($user, true);
    $task->refresh();

    expect($advice->purpose)->toBe('priority_assistant')
        ->and($advice->status)->toBe('proposed')
        ->and($advice->result['recommendations'][0]['task_id'])->toBe($task->id)
        ->and($task->priority)->toBe('high')
        ->and($task->status)->toBe('in_progress')
        ->and($task->due_at->isTomorrow())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->data()['store'] === false
        && str_contains($request->data()['input'], 'Submit research report'));
});

test('assistant page explains when the provider is not configured', function () {
    config()->set('services.ai.api_key', null);
    config()->set('services.ai.model', null);

    $this->actingAs(User::factory()->create())
        ->get(route('assistant'))
        ->assertOk()
        ->assertSee('Provider AI belum dikonfigurasi')
        ->assertSee('Saya setuju agar')
        ->assertSee('tidak mengisi atau mengubah deadline resmi');
});
