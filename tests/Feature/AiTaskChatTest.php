<?php

use App\Models\AiRequest;
use App\Models\Task;
use App\Models\User;
use App\Services\Ai\AiAssistantService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function fakeTaskChatResponse(array $result): array
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

test('task chat requires explicit consent', function () {
    Http::preventStrayRequests();

    $user = User::factory()->create();

    expect(fn () => app(AiAssistantService::class)->answerTaskQuestion(
        $user,
        [['role' => 'user', 'content' => 'Which task is due first?']],
        false,
    ))->toThrow(RuntimeException::class);

    expect(AiRequest::query()->count())->toBe(0);
});

test('task chat is grounded in only the current user task context and stores no raw question', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.openai.com/v1/responses' => Http::response(fakeTaskChatResponse([
            'answer' => 'Basis Data memiliki deadline paling dekat, besok pukul 23.59.',
            'task_ids' => [],
        ])),
    ]);

    $user = User::factory()->create();
    $ownTask = Task::factory()->for($user)->create([
        'title' => 'Basis Data final project',
        'status' => 'in_progress',
        'priority' => 'urgent',
        'due_at' => now()->addDay(),
    ]);
    $otherUser = User::factory()->create();
    Task::factory()->for($otherUser)->create([
        'title' => 'Confidential task from another account',
        'priority' => 'urgent',
    ]);
    $question = 'Which of my tasks has the closest deadline?';

    $response = app(AiAssistantService::class)->answerTaskQuestion(
        $user,
        [['role' => 'user', 'content' => $question]],
        true,
    );

    expect($response->purpose)->toBe('chat_assistant')
        ->and($response->status)->toBe('completed')
        ->and($response->result['answer'])->toContain('deadline paling dekat')
        ->and($response->result['task_ids'])->toBe([])
        ->and(json_encode($response->result))->not->toContain($question)
        ->and($ownTask->fresh()->status)->toBe('in_progress')
        ->and(Task::query()->count())->toBe(2);

    Http::assertSent(function (Request $request) use ($question): bool {
        $input = $request->data()['input'];
        $contextMessage = end($input)['content'];

        return $request->data()['store'] === false
            && $input[0]['content'] === $question
            && str_contains($contextMessage, 'Basis Data final project')
            && ! str_contains($contextMessage, 'Confidential task from another account');
    });
});

test('task chat rejects citations outside the current user task context', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.openai.com/v1/responses' => Http::response(fakeTaskChatResponse([
            'answer' => 'A task exists.',
            'task_ids' => [999999],
        ])),
    ]);

    $user = User::factory()->create();
    Task::factory()->for($user)->create();

    expect(fn () => app(AiAssistantService::class)->answerTaskQuestion(
        $user,
        [['role' => 'user', 'content' => 'Tell me about my tasks.']],
        true,
    ))->toThrow(RuntimeException::class);

    expect($user->aiRequests()->firstOrFail()->status)->toBe('failed')
        ->and($user->aiRequests()->firstOrFail()->failure_code)->toBe('provider_error')
        ->and(Task::query()->count())->toBe(1);
});

test('assistant page exposes consent and citation-backed chat', function () {
    config()->set('services.ai.api_key', null);
    config()->set('services.ai.model', null);

    $this->actingAs(User::factory()->create())
        ->get(route('assistant'))
        ->assertOk()
        ->assertSee('Tanya data tugasmu')
        ->assertSee('ringkasan metadata tugas di atas ke OpenAI')
        ->assertSee('Percakapan hanya bertahan selama halaman ini terbuka');
});
