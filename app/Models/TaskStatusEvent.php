<?php

namespace App\Models;

use Database\Factories\TaskStatusEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskStatusEvent extends Model
{
    /** @use HasFactory<TaskStatusEventFactory> */
    use HasFactory;

    protected $fillable = ['task_id', 'user_id', 'from_status', 'to_status', 'note'];

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
