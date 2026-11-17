<?php

namespace App\Models;

use Database\Factories\TaskProgressEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskProgressEvent extends Model
{
    /** @use HasFactory<TaskProgressEventFactory> */
    use HasFactory;

    protected $fillable = ['task_id', 'user_id', 'from_progress', 'to_progress'];

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
