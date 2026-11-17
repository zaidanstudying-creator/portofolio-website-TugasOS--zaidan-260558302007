<?php

namespace App\Jobs;

use App\Services\Lms\LmsSubmissionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SubmitLmsSubmission implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 3600;

    /** @var list<int> */
    public array $backoff = [5, 15, 30];

    public function __construct(public int $attemptId) {}

    public function handle(LmsSubmissionService $submissions): void
    {
        $submissions->processAttempt($this->attemptId);
    }

    public function failed(?Throwable $exception): void
    {
        app(LmsSubmissionService::class)->markFailed($this->attemptId);
    }

    public function uniqueId(): string
    {
        return 'lms-submission-attempt:'.$this->attemptId;
    }
}
