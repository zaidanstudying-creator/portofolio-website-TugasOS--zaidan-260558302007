<?php

namespace App\Services\Lms;

use App\Models\TaskFile;
use App\Models\User;

interface LmsAdapter
{
    public function platform(): string;

    public function label(): string;

    /**
     * @return list<LmsAssignment>
     */
    public function assignments(User $user, ?string $courseId = null): array;

    public function assignment(User $user, string $assignmentId): LmsAssignment;

    public function findSubmissionByKey(User $user, string $assignmentId, string $idempotencyKey): ?LmsSubmissionReceipt;

    public function submit(User $user, string $assignmentId, TaskFile $file, string $idempotencyKey): LmsSubmissionReceipt;

    public function verify(User $user, string $assignmentId, LmsSubmissionReceipt $receipt): LmsSubmissionReceipt;
}
