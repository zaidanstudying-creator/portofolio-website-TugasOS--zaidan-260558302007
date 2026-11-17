<?php

namespace App\Models;

use Database\Factories\TaskFileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $task_id
 * @property int $user_id
 * @property string $kind
 * @property string $original_name
 * @property string $disk
 * @property string $path
 * @property string|null $mime_type
 * @property int $byte_size
 * @property int $version
 * @property bool $is_final
 * @property string|null $sha256
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TaskFile extends Model
{
    /** @use HasFactory<TaskFileFactory> */
    use HasFactory;

    protected $fillable = ['task_id', 'user_id', 'kind', 'original_name', 'disk', 'path', 'mime_type', 'byte_size', 'version', 'is_final', 'sha256'];

    protected function casts(): array
    {
        return ['is_final' => 'boolean'];
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
