<?php

namespace App\Services\Lms;

use DateTimeImmutable;

class LmsSubmissionReceipt
{
    /**
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public string $externalSubmissionId,
        public bool $verified,
        public ?DateTimeImmutable $submittedAt,
        public ?string $confirmationReference,
        public ?string $submissionUrl,
        public array $evidence = [],
    ) {}
}
