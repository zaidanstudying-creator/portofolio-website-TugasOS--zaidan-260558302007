<?php

namespace App\Models;

use Database\Factories\TaskSubmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $task_id
 * @property int $user_id
 * @property int|null $proof_file_id
 * @property string $platform
 * @property string $status
 * @property string $assessment_status
 * @property string $confirmation_method
 * @property string|null $external_assignment_id
 * @property string|null $submission_url
 * @property string|null $confirmation_reference
 * @property Carbon|null $submitted_at
 * @property Carbon|null $assessed_at
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TaskSubmission extends Model
{
    /** @use HasFactory<TaskSubmissionFactory> */
    use HasFactory;

    protected $attributes = ['assessment_status' => 'pending'];

    protected $fillable = ['task_id', 'user_id', 'proof_file_id', 'platform', 'status', 'assessment_status', 'confirmation_method', 'external_assignment_id', 'submission_url', 'confirmation_reference', 'submitted_at', 'assessed_at', 'notes'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'assessed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<TaskFile, $this>
     */
    public function proofFile(): BelongsTo
    {
        return $this->belongsTo(TaskFile::class, 'proof_file_id');
    }

    /**
     * @return HasMany<SubmissionAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(SubmissionAttempt::class);
    }

    /**
     * @return HasMany<SubmissionAssessmentEvent, $this>
     */
    public function assessmentEvents(): HasMany
    {
        return $this->hasMany(SubmissionAssessmentEvent::class)->orderBy('created_at')->orderBy('id');
    }

    protected static function booted(): void
    {
        static::created(function (TaskSubmission $submission): void {
            $submission->assessmentEvents()->create([
                'user_id' => $submission->user_id,
                'event_type' => 'initial',
                'from_status' => null,
                'to_status' => $submission->assessment_status,
            ]);
        });
    }
}
