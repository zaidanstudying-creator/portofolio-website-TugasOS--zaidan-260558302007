<?php

namespace App\Models;

use Database\Factories\TaskItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskItem extends Model
{
    /** @use HasFactory<TaskItemFactory> */
    use HasFactory;

    protected $fillable = ['task_id', 'title', 'position', 'is_completed', 'completed_at'];

    protected function casts(): array
    {
        return ['is_completed' => 'boolean', 'completed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
