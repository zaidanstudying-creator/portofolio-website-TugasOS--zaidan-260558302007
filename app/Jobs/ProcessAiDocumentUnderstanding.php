<?php

namespace App\Jobs;

use App\Models\AiRequest;
use App\Services\Ai\AiAssistantService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessAiDocumentUnderstanding implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 50;

    public int $uniqueFor = 3600;

    /** @var list<int> */
    public array $backoff = [10, 30];

    public function __construct(public int $aiRequestId) {}

    public function handle(AiAssistantService $assistant): void
    {
        $request = AiRequest::query()->find($this->aiRequestId);

        if ($request) {
            $assistant->processQueuedDocumentUnderstanding($request);
        }
    }

    public function failed(?Throwable $exception): void
    {
        AiRequest::query()
            ->whereKey($this->aiRequestId)
            ->whereIn('status', ['queued', 'processing'])
            ->update([
                'status' => 'failed',
                'failure_code' => 'provider_error',
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function uniqueId(): string
    {
        return 'ai-document-understanding:'.$this->aiRequestId;
    }
}
