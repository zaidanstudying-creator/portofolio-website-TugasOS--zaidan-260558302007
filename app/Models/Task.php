<?php

namespace App\Models;

use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $parent_task_id
 * @property int|null $category_id
 * @property int|null $course_id
 * @property int|null $semester_id
 * @property string $title
 * @property string|null $description
 * @property string $type
 * @property string $status
 * @property string $priority
 * @property Carbon|null $starts_at
 * @property Carbon|null $due_at
 * @property Carbon|null $completed_at
 * @property int|null $estimated_minutes
 * @property int $weight
 * @property string|null $notes
 * @property int|null $recurrence_rule_id
 * @property Carbon|null $recurrence_occurrence_date
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'parent_task_id', 'category_id', 'course_id', 'semester_id', 'title', 'description',
        'type', 'status', 'priority', 'starts_at', 'due_at', 'completed_at', 'estimated_minutes', 'weight', 'notes',
        'recurrence_rule_id', 'recurrence_occurrence_date', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'archived_at' => 'datetime',
            'recurrence_occurrence_date' => 'date',
        ];
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
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_task_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * @return BelongsTo<Semester, $this>
     */
    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_task_id');
    }

    /**
     * @return HasMany<TaskItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(TaskItem::class)->orderBy('position');
    }

    /**
     * @return BelongsToMany<Assigner, $this>
     */
    public function assigners(): BelongsToMany
    {
        return $this->belongsToMany(Assigner::class, 'task_assigners')->withTimestamps();
    }

    /**
     * @return HasMany<TaskStatusEvent, $this>
     */
    public function statusEvents(): HasMany
    {
        return $this->hasMany(TaskStatusEvent::class);
    }

    /**
     * @return HasMany<TaskProgressEvent, $this>
     */
    public function progressEvents(): HasMany
    {
        return $this->hasMany(TaskProgressEvent::class);
    }

    /**
     * @return HasMany<TaskSchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(TaskSchedule::class)->orderBy('starts_at');
    }

    /**
     * @return HasMany<TaskFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(TaskFile::class)->latest();
    }

    /**
     * @return HasMany<TaskLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(TaskLink::class)->latest();
    }

    /**
     * @return HasMany<TaskNote, $this>
     */
    public function taskNotes(): HasMany
    {
        return $this->hasMany(TaskNote::class)->latest();
    }

    /**
     * @return HasMany<TaskSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(TaskSubmission::class)->latest();
    }

    /**
     * @return HasOne<Reminder, $this>
     */
    public function reminder(): HasOne
    {
        return $this->hasOne(Reminder::class);
    }

    /**
     * @return BelongsTo<RecurrenceRule, $this>
     */
    public function recurrenceRule(): BelongsTo
    {
        return $this->belongsTo(RecurrenceRule::class);
    }

    public function computedProgress(?string $aggregation = null): int
    {
        $aggregation ??= $this->user?->settings['progress_aggregation'] ?? 'weighted';
        $this->loadProgressTree();

        return $this->calculateProgress($aggregation);
    }

    private function loadProgressTree(): void
    {
        $frontier = new EloquentCollection([$this]);
        $visitedIds = [];

        while ($frontier->isNotEmpty()) {
            $frontier = $frontier
                ->reject(fn (Task $task): bool => in_array((int) $task->getKey(), $visitedIds, true))
                ->values();

            if ($frontier->isEmpty()) {
                break;
            }

            $frontier->loadMissing(['children', 'items']);
            $visitedIds = array_merge($visitedIds, $frontier->modelKeys());
            $frontier = new EloquentCollection($frontier->flatMap(fn (Task $task) => $task->children)->values()->all());
        }
    }

    /**
     * @param  list<int>  $ancestorIds
     */
    private function calculateProgress(string $aggregation, array $ancestorIds = []): int
    {
        $taskId = (int) $this->getKey();

        if (in_array($taskId, $ancestorIds, true)) {
            return $this->status === 'done' ? 100 : 0;
        }

        $ancestorIds[] = $taskId;

        $children = $this->children;

        if ($children->isNotEmpty()) {
            if ($aggregation === 'equal') {
                return (int) round($children->average(fn (Task $child): int => $child->calculateProgress($aggregation, $ancestorIds)));
            }

            $totalWeight = max(1, (int) $children->sum('weight'));

            return (int) round($children->sum(fn (Task $child): float => $child->calculateProgress($aggregation, $ancestorIds) * $child->weight) / $totalWeight);
        }

        $items = $this->items;

        if ($items->isNotEmpty()) {
            return (int) round($items->where('is_completed', true)->count() / $items->count() * 100);
        }

        return $this->status === 'done' ? 100 : 0;
    }
}
