<?php

namespace App\Models;

use Database\Factories\TaskScheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $task_id
 * @property int $user_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property bool $is_all_day
 * @property string|null $label
 */
class TaskSchedule extends Model
{
    /** @use HasFactory<TaskScheduleFactory> */
    use HasFactory;

    protected $fillable = ['task_id', 'user_id', 'starts_at', 'ends_at', 'is_all_day', 'label'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_all_day' => 'boolean'];
    }

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
