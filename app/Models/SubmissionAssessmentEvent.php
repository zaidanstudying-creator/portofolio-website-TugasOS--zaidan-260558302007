<?php

namespace App\Models;

use Database\Factories\SubmissionAssessmentEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubmissionAssessmentEvent extends Model
{
    /** @use HasFactory<SubmissionAssessmentEventFactory> */
    use HasFactory;

    protected $fillable = ['task_submission_id', 'user_id', 'event_type', 'from_status', 'to_status'];

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
