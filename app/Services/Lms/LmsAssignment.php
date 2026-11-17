<?php

namespace App\Services\Lms;

use DateTimeImmutable;

class LmsAssignment
{
    /**
     * @param  list<string>  $allowedMimeTypes
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $courseName,
        public ?DateTimeImmutable $dueAt,
        public array $allowedMimeTypes,
        public int $maxFileBytes,
        public bool $resubmissionAllowed,
        public ?string $submissionUrl = null,
    ) {}
}
