<?php

use App\Jobs\SubmitLmsSubmission;
use App\Models\Task;
use App\Models\TaskFile;
use App\Models\User;
use App\Services\Lms\LmsAdapter;
use App\Services\Lms\LmsAdapterRegistry;
use App\Services\Lms\LmsAssignment;
use App\Services\Lms\LmsSubmissionReceipt;
use App\Services\Lms\LmsSubmissionService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;

test('LMS submission retries verify the existing idempotent receipt and never resubmit it', function () {
    Storage::fake('local');
    Queue::fake([SubmitLmsSubmission::class]);

    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create(['title' => 'Laporan final', 'status' => 'in_progress']);
    $contents = "%PDF-1.4\nfinal submission";
    Storage::disk('local')->put('tasks/final.pdf', $contents);
    $file = $user->taskFiles()->create([
        'task_id' => $task->id,
        'kind' => 'final',
        'original_name' => 'final.pdf',
        'disk' => 'local',
        'path' => 'tasks/final.pdf',
        'mime_type' => 'application/pdf',
        'byte_size' => strlen($contents),
        'version' => 1,
        'is_final' => true,
    ]);
    Storage::disk('local')->put('tasks/revised-final.pdf', $contents.' revised');
    $revisedFile = $user->taskFiles()->create([
        'task_id' => $task->id,
        'kind' => 'final',
        'original_name' => 'revised-final.pdf',
        'disk' => 'local',
        'path' => 'tasks/revised-final.pdf',
        'mime_type' => 'application/pdf',
        'byte_size' => strlen($contents.' revised'),
        'version' => 2,
        'is_final' => true,
    ]);
    $adapter = new class implements LmsAdapter
    {
        public array $receipts = [];

        public int $submitCount = 0;

        public int $verifyCount = 0;

        public int $maxFileBytes = 1000;

        public bool $throwOnFirstVerify = true;

        public bool $verified = true;

        public function platform(): string
        {
            return 'test-lms';
        }

        public function label(): string
        {
            return 'Test LMS';
        }

        public function assignments(User $user, ?string $courseId = null): array
        {
            return [$this->assignment($user, 'assignment-1')];
        }

        public function assignment(User $user, string $assignmentId): LmsAssignment
        {
            return new LmsAssignment($assignmentId, 'Final project', 'Informatika', null, ['application/pdf'], $this->maxFileBytes, true);
        }

        public function findSubmissionByKey(User $user, string $assignmentId, string $idempotencyKey): ?LmsSubmissionReceipt
        {
            return $this->receipts[$idempotencyKey] ?? null;
        }

        public function submit(User $user, string $assignmentId, TaskFile $file, string $idempotencyKey): LmsSubmissionReceipt
        {
            $this->submitCount++;
            $this->receipts[$idempotencyKey] = new LmsSubmissionReceipt(
                'remote-submission-1',
                false,
                now()->toDateTimeImmutable(),
                'receipt-1',
                'https://lms.test/submissions/1?access_token=secret&view=1',
                ['access_token' => 'secret-evidence-token', 'safe' => [
                    'id' => 'remote-submission-1',
                    'resource_url' => 'https://lms.test/submissions/1?api_key=secret&view=1',
                    'session_token' => 'secret',
                ]],
            );

            return $this->receipts[$idempotencyKey];
        }

        public function verify(User $user, string $assignmentId, LmsSubmissionReceipt $receipt): LmsSubmissionReceipt
        {
            $this->verifyCount++;

            if ($this->throwOnFirstVerify && $this->verifyCount === 1) {
                throw new RuntimeException('Temporary verification timeout.');
            }

            return new LmsSubmissionReceipt(
                $receipt->externalSubmissionId,
                $this->verified,
                $receipt->submittedAt,
                $receipt->confirmationReference,
                $receipt->submissionUrl,
                $receipt->evidence,
            );
        }
    };

    $this->app->instance(LmsAdapterRegistry::class, new LmsAdapterRegistry([$adapter]));
    $this->actingAs($user);

    Livewire::test('pages::submissions')
        ->set('lmsPlatform', 'test-lms')
        ->call('loadLmsAssignments')
        ->assertHasNoErrors()
        ->assertSet('availableAssignments.0.title', 'Final project')
        ->assertSee('Konfirmasi dan kirim')
        ->assertSee('wire:confirm');

    $service = app(LmsSubmissionService::class);
    $submission = $service->queue($user, $task->id, $file->id, 'test-lms', 'assignment-1');
    $attempt = $submission->attempts->firstOrFail();

    expect($submission->status)->toBe('sending')
        ->and($attempt->status)->toBe('queued');

    expect(fn () => $service->processAttempt($attempt->id))->toThrow(RuntimeException::class);

    $failSubmissionStateWrite = true;
    DB::listen(function (QueryExecuted $query) use (&$failSubmissionStateWrite): void {
        if ($failSubmissionStateWrite
            && str_starts_with(strtolower(ltrim($query->sql)), 'update')
            && str_contains($query->sql, 'task_submissions')) {
            $failSubmissionStateWrite = false;

            throw new RuntimeException('Simulated submission state write failure.');
        }
    });

    expect(fn () => $service->processAttempt($attempt->id))
        ->toThrow(RuntimeException::class, 'Simulated submission state write failure.');

    expect($submission->fresh()->status)->toBe('sending')
        ->and($attempt->fresh()->status)->toBe('sending');

    $service->processAttempt($attempt->id);

    expect($adapter->submitCount)->toBe(1)
        ->and($submission->fresh()->status)->toBe('verified')
        ->and($attempt->fresh()->status)->toBe('verified')
        ->and($attempt->fresh()->response_payload['external_submission_id'])->toBe('remote-submission-1')
        ->and($submission->fresh()->submission_url)->toBe('https://lms.test/submissions/1?view=1')
        ->and($attempt->fresh()->response_payload['evidence'])->toBe([
            'safe' => [
                'id' => 'remote-submission-1',
                'resource_url' => 'https://lms.test/submissions/1?view=1',
            ],
        ]);

    $service->processAttempt($attempt->id);

    expect($adapter->submitCount)->toBe(1);

    $adapter->throwOnFirstVerify = false;
    $adapter->verified = false;
    $unverifiedSubmission = $service->queue($user, $task->id, $file->id, 'test-lms', 'assignment-1');
    $unverifiedAttempt = $unverifiedSubmission->attempts->last();
    $service->processAttempt($unverifiedAttempt->id);

    expect($unverifiedSubmission->fresh()->status)->toBe('requires_manual_action')
        ->and($unverifiedAttempt->fresh()->status)->toBe('requires_manual_action');

    Livewire::test('pages::submissions')
        ->assertSee('LMS belum mengonfirmasi pengiriman ini. Periksa assignment secara manual sebelum mencoba mengirim ulang.');

    expect(fn () => $service->queue($user, $task->id, $file->id, 'test-lms', 'assignment-1'))
        ->toThrow(ValidationException::class)
        ->and($unverifiedSubmission->fresh()->status)->toBe('requires_manual_action')
        ->and($unverifiedSubmission->fresh()->attempts)->toHaveCount(1);

    $retrySubmission = $service->queue($user, $task->id, $file->id, 'test-lms', 'assignment-2');
    $retryAttempt = $retrySubmission->attempts->last();
    $service->markFailed($retryAttempt->id);

    expect(fn () => $service->queue($user, $task->id, $revisedFile->id, 'test-lms', 'assignment-2'))
        ->toThrow(ValidationException::class)
        ->and($retrySubmission->fresh()->status)->toBe('failed')
        ->and($retrySubmission->fresh()->proof_file_id)->toBe($file->id);

    $file->update(['is_final' => false]);

    Livewire::test('pages::submissions')
        ->assertSee('Coba lagi dengan aman')
        ->assertSee('wire:confirm')
        ->call('retryLmsSubmission', $retrySubmission->id)
        ->assertHasNoErrors()
        ->assertSee('idempotency key yang sama');

    $retryAgain = $retrySubmission->fresh()->load('attempts');

    expect($retryAgain->getKey())->toBe($retrySubmission->getKey())
        ->and($retryAgain->attempts->last()->idempotency_key)->toBe($retryAttempt->idempotency_key)
        ->and($retryAgain->attempts)->toHaveCount(1);

    $adapter->maxFileBytes = 1;

    expect(fn () => $service->queue($user, $task->id, $file->id, 'test-lms', 'assignment-1'))
        ->toThrow(ValidationException::class);
});

test('LMS submission only accepts the current final file for new submissions', function () {
    Storage::fake('local');
    Queue::fake([SubmitLmsSubmission::class]);

    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create();
    Storage::disk('local')->put('tasks/old-final.pdf', '%PDF-1.4 old');
    Storage::disk('local')->put('tasks/current-final.pdf', '%PDF-1.4 current');

    $oldFile = $user->taskFiles()->create([
        'task_id' => $task->id,
        'kind' => 'final',
        'original_name' => 'old-final.pdf',
        'disk' => 'local',
        'path' => 'tasks/old-final.pdf',
        'mime_type' => 'application/pdf',
        'byte_size' => 12,
        'version' => 1,
        'is_final' => false,
    ]);
    $currentFile = $user->taskFiles()->create([
        'task_id' => $task->id,
        'kind' => 'final',
        'original_name' => 'current-final.pdf',
        'disk' => 'local',
        'path' => 'tasks/current-final.pdf',
        'mime_type' => 'application/pdf',
        'byte_size' => 16,
        'version' => 2,
        'is_final' => true,
    ]);
    $adapter = new class implements LmsAdapter
    {
        public function platform(): string
        {
            return 'current-final-test';
        }

        public function label(): string
        {
            return 'Current final test';
        }

        public function assignments(User $user, ?string $courseId = null): array
        {
            return [$this->assignment($user, 'assignment-current-final')];
        }

        public function assignment(User $user, string $assignmentId): LmsAssignment
        {
            return new LmsAssignment($assignmentId, 'Final assignment', 'Course', null, ['application/pdf'], 1000, true);
        }

        public function findSubmissionByKey(User $user, string $assignmentId, string $idempotencyKey): ?LmsSubmissionReceipt
        {
            return null;
        }

        public function submit(User $user, string $assignmentId, TaskFile $file, string $idempotencyKey): LmsSubmissionReceipt
        {
            throw new RuntimeException('This test must not send a submission.');
        }

        public function verify(User $user, string $assignmentId, LmsSubmissionReceipt $receipt): LmsSubmissionReceipt
        {
            return $receipt;
        }
    };

    $this->app->instance(LmsAdapterRegistry::class, new LmsAdapterRegistry([$adapter]));
    $this->actingAs($user);

    $component = Livewire::test('pages::submissions')
        ->set('lmsTaskId', (string) $task->id)
        ->assertSee('current-final.pdf');

    expect($component->instance()->finalFiles()->modelKeys())->toBe([$currentFile->id]);

    $component
        ->set('lmsPlatform', $adapter->platform())
        ->set('lmsTaskId', (string) $task->id)
        ->set('lmsFileId', (string) $oldFile->id)
        ->set('lmsAssignmentId', 'assignment-current-final')
        ->call('submitToLms')
        ->assertHasErrors('lmsFileId');

    expect(fn () => app(LmsSubmissionService::class)->queue(
        $user,
        $task->id,
        $oldFile->id,
        $adapter->platform(),
        'assignment-current-final',
    ))->toThrow(ValidationException::class)
        ->and($user->taskSubmissions()->count())->toBe(0);

    $submission = app(LmsSubmissionService::class)->queue(
        $user,
        $task->id,
        $currentFile->id,
        $adapter->platform(),
        'assignment-current-final',
    );

    expect($submission->proof_file_id)->toBe($currentFile->id);
    Queue::assertPushed(SubmitLmsSubmission::class, 1);
});
