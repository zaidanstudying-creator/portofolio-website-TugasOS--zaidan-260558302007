<?php

namespace App\Services\Lms;

use App\Jobs\SubmitLmsSubmission;
use App\Models\SubmissionAttempt;
use App\Models\TaskFile;
use App\Models\TaskSubmission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class LmsSubmissionService
{
    public function __construct(private readonly LmsAdapterRegistry $adapters) {}

    public function queue(User $user, int $taskId, int $fileId, string $platform, string $assignmentId): TaskSubmission
    {
        $adapter = $this->adapters->forPlatform($platform);
        $task = $user->tasks()->whereNull('archived_at')->findOrFail($taskId);
        $file = $user->taskFiles()->where('task_id', $task->getKey())->where('kind', 'final')->findOrFail($fileId);
        $assignment = $adapter->assignment($user, $assignmentId);

        if ($assignment->id !== $assignmentId) {
            throw ValidationException::withMessages(['lmsAssignmentId' => 'Assignment LMS yang dipilih tidak cocok.']);
        }

        $this->validateFile($file, $assignment);

        return DB::transaction(function () use ($user, $task, $file, $platform, $assignment): TaskSubmission {
            $lockedTask = $user->tasks()->whereNull('archived_at')->lockForUpdate()->whereKey($task->getKey())->firstOrFail();
            $latestSubmission = $user->taskSubmissions()
                ->where('task_id', $lockedTask->getKey())
                ->where('platform', $platform)
                ->where('external_assignment_id', $assignment->id)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($latestSubmission?->status === 'sending') {
                if ($latestSubmission->proof_file_id !== $file->getKey()) {
                    throw ValidationException::withMessages([
                        'lmsFileId' => 'Pengiriman yang sedang diproses menggunakan file final yang berbeda.',
                    ]);
                }

                return $latestSubmission;
            }

            if ($latestSubmission?->status === 'requires_manual_action') {
                throw ValidationException::withMessages([
                    'lmsAssignmentId' => 'LMS belum mengonfirmasi pengiriman terakhir. Periksa assignment secara manual sebelum mengirim ulang.',
                ]);
            }

            if ($latestSubmission?->status === 'failed') {
                $failedAttempt = $latestSubmission->attempts()
                    ->where('status', 'failed')
                    ->latest('attempt_number')
                    ->lockForUpdate()
                    ->first();

                if (! $failedAttempt) {
                    throw ValidationException::withMessages([
                        'lmsAssignmentId' => 'Riwayat percobaan tidak lengkap. Periksa status di LMS sebelum membuat pengiriman baru.',
                    ]);
                }

                if ($latestSubmission->proof_file_id !== $file->getKey()) {
                    throw ValidationException::withMessages([
                        'lmsFileId' => 'Retry aman harus menggunakan file yang sama dengan percobaan sebelumnya. Periksa LMS sebelum mengganti file.',
                    ]);
                }

                $latestSubmission->update([
                    'status' => 'sending',
                    'confirmation_method' => 'lms_api',
                ]);
                SubmitLmsSubmission::dispatch($failedAttempt->getKey())->afterCommit();

                return $latestSubmission->load('attempts');
            }

            if (! $file->is_final) {
                throw ValidationException::withMessages([
                    'lmsFileId' => 'Pilih versi final terbaru sebelum pengiriman.',
                ]);
            }

            if ($latestSubmission && ! $assignment->resubmissionAllowed) {
                throw ValidationException::withMessages([
                    'lmsAssignmentId' => 'Assignment ini tidak mengizinkan pengiriman ulang. Periksa status dan bukti di riwayat pengumpulan.',
                ]);
            }

            $submission = $latestSubmission?->status === 'failed'
                ? $latestSubmission
                : $user->taskSubmissions()->create([
                    'task_id' => $lockedTask->getKey(),
                    'proof_file_id' => $file->getKey(),
                    'platform' => $platform,
                    'status' => 'sending',
                    'confirmation_method' => 'lms_api',
                    'external_assignment_id' => $assignment->id,
                ]);

            if ($submission->status === 'failed') {
                $submission->update([
                    'proof_file_id' => $file->getKey(),
                    'status' => 'sending',
                    'confirmation_method' => 'lms_api',
                ]);
            }

            $previousAttemptNumber = $submission->attempts()
                ->orderByDesc('attempt_number')
                ->lockForUpdate()
                ->value('attempt_number');
            $attemptNumber = (int) $previousAttemptNumber + 1;
            $attempt = $submission->attempts()->create([
                'user_id' => $user->getKey(),
                'attempt_number' => $attemptNumber,
                'idempotency_key' => (string) Str::uuid(),
                'status' => 'queued',
            ]);

            SubmitLmsSubmission::dispatch($attempt->getKey())->afterCommit();

            return $submission->load('attempts');
        });
    }

    public function processAttempt(int $attemptId): void
    {
        $attempt = SubmissionAttempt::query()
            ->with(['submission.task.user', 'submission.proofFile'])
            ->findOrFail($attemptId);

        if ($attempt->status === 'verified') {
            return;
        }

        $submission = $attempt->submission;
        $user = $submission->task->user;
        $file = $submission->proofFile;

        if ($submission->status !== 'sending' || ! $file) {
            return;
        }

        $attempt->update([
            'status' => 'sending',
            'started_at' => $attempt->started_at ?? now(),
        ]);

        $adapter = $this->adapters->forPlatform($submission->platform);
        $assignment = $adapter->assignment($user, (string) $submission->external_assignment_id);

        if ($assignment->id !== $submission->external_assignment_id) {
            throw new RuntimeException('LMS returned an assignment that does not match the requested identifier.');
        }

        $this->validateFile($file, $assignment);

        $receipt = $adapter->findSubmissionByKey($user, $assignment->id, $attempt->idempotency_key)
            ?? $adapter->submit($user, $assignment->id, $file, $attempt->idempotency_key);
        $receipt = $adapter->verify($user, $assignment->id, $receipt);

        DB::transaction(function () use ($attempt, $submission, $receipt): void {
            $lockedAttempt = SubmissionAttempt::query()->lockForUpdate()->whereKey($attempt->getKey())->firstOrFail();
            $lockedSubmission = TaskSubmission::query()->lockForUpdate()->whereKey($submission->getKey())->firstOrFail();

            if ($lockedAttempt->status === 'verified' || $lockedSubmission->status !== 'sending') {
                return;
            }

            $lockedAttempt->update([
                'status' => $receipt->verified ? 'verified' : 'requires_manual_action',
                'completed_at' => now(),
                'response_payload' => [
                    'external_submission_id' => $receipt->externalSubmissionId,
                    'verified' => $receipt->verified,
                    'confirmation_reference' => $receipt->confirmationReference,
                    'submission_url' => $this->sanitizeUrl($receipt->submissionUrl),
                    'evidence' => $this->sanitizeEvidence($receipt->evidence),
                ],
                'failure_reason' => $receipt->verified ? null : 'LMS belum mengonfirmasi pengumpulan. Periksa assignment secara manual.',
            ]);

            $lockedSubmission->update([
                'status' => $receipt->verified ? 'verified' : 'requires_manual_action',
                'submitted_at' => $receipt->submittedAt,
                'submission_url' => $this->sanitizeUrl($receipt->submissionUrl),
                'confirmation_reference' => $receipt->confirmationReference,
            ]);
        });
    }

    public function markFailed(int $attemptId): void
    {
        DB::transaction(function () use ($attemptId): void {
            $attempt = SubmissionAttempt::query()->with('submission')->lockForUpdate()->find($attemptId);

            if (! $attempt || $attempt->status === 'verified') {
                return;
            }

            $attempt->update([
                'status' => 'failed',
                'completed_at' => now(),
                'failure_reason' => 'Pengiriman gagal setelah percobaan ulang. Periksa LMS sebelum mencoba kembali.',
            ]);

            if ($attempt->submission->status === 'sending') {
                $attempt->submission->update(['status' => 'failed']);
            }
        });
    }

    private function validateFile(TaskFile $file, LmsAssignment $assignment): void
    {
        $disk = Storage::disk($file->disk);

        if (! $disk->exists($file->path)) {
            throw ValidationException::withMessages(['lmsFileId' => 'File final tidak tersedia di penyimpanan privat.']);
        }

        $fileSize = $disk->size($file->path);
        $mimeType = $disk->mimeType($file->path) ?: $file->mime_type;

        if ($assignment->maxFileBytes > 0 && $fileSize > $assignment->maxFileBytes) {
            throw ValidationException::withMessages(['lmsFileId' => 'Ukuran file melebihi batas assignment LMS.']);
        }

        if ($assignment->allowedMimeTypes !== [] && (! $mimeType || ! in_array($mimeType, $assignment->allowedMimeTypes, true))) {
            throw ValidationException::withMessages(['lmsFileId' => 'Format file tidak sesuai persyaratan assignment LMS.']);
        }
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function sanitizeEvidence(array $evidence): array
    {
        foreach ($evidence as $key => $value) {
            if (preg_match('/token|authorization|password|secret|cookie/i', (string) $key)) {
                unset($evidence[$key]);

                continue;
            }

            if (is_array($value)) {
                $evidence[$key] = $this->sanitizeEvidence($value);

                continue;
            }

            if (is_string($value) && preg_match('/^https?:\/\//i', $value)) {
                $evidence[$key] = $this->sanitizeUrl($value) ?? '[URL removed]';
            }
        }

        return $evidence;
    }

    private function sanitizeUrl(?string $url): ?string
    {
        if (! $url || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        parse_str($parts['query'] ?? '', $query);

        foreach (array_keys($query) as $key) {
            if (preg_match('/token|auth|password|secret|signature|session|cookie|key/i', (string) $key)) {
                unset($query[$key]);
            }
        }

        $host = str_contains($parts['host'], ':') ? '['.$parts['host'].']' : $parts['host'];
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $safeUrl = ($parts['scheme'] ?? 'https').'://'.$host.$port.($parts['path'] ?? '');

        if ($query !== []) {
            $safeUrl .= '?'.http_build_query($query);
        }

        return $safeUrl;
    }
}
