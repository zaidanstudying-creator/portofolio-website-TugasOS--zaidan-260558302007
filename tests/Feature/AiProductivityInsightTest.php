<?php

use App\Models\AiRequest;
use App\Models\Task;
use App\Models\User;
use App\Services\Ai\AiAssistantService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function productivityInsightFixtureResult(): array
{
    return [
        'summary' => 'Sebagian besar tugas dalam periode ini masih aktif.',
        'patterns' => ['Tugas prioritas tinggi memiliki progres yang lebih besar.'],
        'potential_delay_causes' => ['Estimasi durasi mungkin lebih kecil dari waktu pengerjaan yang dibutuhkan.'],
        'schedule_suggestions' => ['Sisihkan blok fokus sebelum tenggat terdekat.'],
        'data_limitations' => 'Data durasi aktual dan sesi pengerjaan tidak tersedia.',
    ];
}

function fakeProductivityInsightResponse(array $result): array
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

test('productivity data is not sent without explicit consent', function () {
    Http::preventStrayRequests();

    $user = User::factory()->create();
    Task::factory()->for($user)->create();

    expect(fn () => app(AiAssistantService::class)->generateProductivityInsight(
        $user,
        today()->subDays(7)->toDateString(),
        today()->toDateString(),
        'deadline',
        false,
    ))->toThrow(RuntimeException::class);

    expect(AiRequest::query()->count())->toBe(0);
});

test('productivity insight uses the selected period and anonymized metrics only', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.openai.com/v1/responses' => Http::response(fakeProductivityInsightResponse(productivityInsightFixtureResult())),
    ]);

    $user = User::factory()->create();
    $selectedTask = Task::factory()->for($user)->create([
        'title' => 'Private research on student wellbeing',
        'status' => 'in_progress',
        'priority' => 'high',
        'created_at' => today()->subDays(2)->setTime(12, 0),
        'due_at' => today()->addDay()->setTime(23, 59),
        'estimated_minutes' => 180,
    ]);
    $outsideTask = Task::factory()->for($user)->create([
        'title' => 'Outside selected period',
        'created_at' => today()->subDays(30)->setTime(12, 0),
        'due_at' => today()->subDays(29)->setTime(23, 59),
    ]);
    $outsideTask->progressEvents()->create([
        'user_id' => $user->id,
        'from_progress' => 10,
        'to_progress' => 80,
    ]);
    $selectedTask->progressEvents()->create([
        'user_id' => $user->id,
        'from_progress' => 0,
        'to_progress' => 30,
    ]);
    $selectedChild = Task::factory()->for($user)->create([
        'parent_task_id' => $selectedTask->id,
        'created_at' => today()->subDay()->setTime(12, 0),
    ]);
    $selectedChild->progressEvents()->create([
        'user_id' => $user->id,
        'from_progress' => 0,
        'to_progress' => 20,
    ]);

    $startDate = today()->subDays(7)->toDateString();
    $endDate = today()->toDateString();
    $insight = app(AiAssistantService::class)->generateProductivityInsight(
        $user,
        $startDate,
        $endDate,
        'created',
        true,
    );

    expect($insight->purpose)->toBe('productivity_insight')
        ->and($insight->status)->toBe('proposed')
        ->and($insight->result['period'])->toBe([
            'start' => $startDate,
            'end' => $endDate,
            'grouped_by' => 'created',
        ])
        ->and(Task::query()->count())->toBe(3)
        ->and($selectedTask->fresh()->priority)->toBe('high');

    Http::assertSent(function (Request $request) use ($startDate, $endDate): bool {
        $input = json_decode($request->data()['input'], true, flags: JSON_THROW_ON_ERROR);

        return $request->data()['store'] === false
            && $input['period']['start'] === $startDate
            && $input['period']['end'] === $endDate
            && $input['period']['grouped_by'] === 'created'
            && $input['metrics']['task_count'] === 1
            && $input['metrics']['progress_event_count'] === 2
            && $input['metrics']['progress_events_average_change_points'] === 25
            && ! str_contains($request->data()['input'], 'Private research on student wellbeing')
            && ! str_contains($request->data()['input'], 'Outside selected period');
    });
});

test('productivity insight skips the provider when the selected period has no tasks', function () {
    Http::preventStrayRequests();

    $user = User::factory()->create();

    expect(fn () => app(AiAssistantService::class)->generateProductivityInsight(
        $user,
        today()->subDays(7)->toDateString(),
        today()->toDateString(),
        'deadline',
        true,
    ))->toThrow(RuntimeException::class);

    expect($user->aiRequests()->count())->toBe(0);
});

test('AI page exposes selected-period and privacy controls for productivity insight', function () {
    config()->set('services.ai.api_key', null);
    config()->set('services.ai.model', null);

    $this->actingAs(User::factory()->create())
        ->get(route('assistant'))
        ->assertOk()
        ->assertSee('Lihat pola produktivitas')
        ->assertSee('judul tugas tidak dikirim')
        ->assertSee('ringkasan anonim progres tugas');
});
