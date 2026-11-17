<?php

namespace App\Models;

use Database\Factories\SubmissionAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubmissionAttempt extends Model
{
    /** @use HasFactory<SubmissionAttemptFactory> */
    use HasFactory;

    protected $fillable = ['task_submission_id', 'user_id', 'attempt_number', 'idempotency_key', 'status', 'started_at', 'completed_at', 'response_payload', 'failure_reason'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'completed_at' => 'datetime', 'response_payload' => 'array'];
    }

    /**
     * @return BelongsTo<TaskSubmission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(TaskSubmission::class, 'task_submission_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
