<?php

namespace App\Models;

use Database\Factories\RecurrenceRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $template_task_id
 * @property string $frequency
 * @property int $interval
 * @property list<int>|null $weekdays
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property Carbon|null $next_run_at
 * @property bool $is_active
 */
class RecurrenceRule extends Model
{
    /** @use HasFactory<RecurrenceRuleFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'template_task_id', 'frequency', 'interval', 'weekdays', 'starts_on', 'ends_on', 'next_run_at', 'is_active'];

    protected function casts(): array
    {
        return ['weekdays' => 'array', 'starts_on' => 'date', 'ends_on' => 'date', 'next_run_at' => 'date', 'is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function templateTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'template_task_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
