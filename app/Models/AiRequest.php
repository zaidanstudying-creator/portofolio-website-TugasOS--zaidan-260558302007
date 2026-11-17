<?php

namespace App\Models;

use Database\Factories\AiRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $task_id
 * @property int|null $task_file_id
 * @property string $purpose
 * @property string $provider
 * @property string $model
 * @property string $status
 * @property string $input_hash
 * @property array<string, mixed>|null $result
 * @property Carbon|null $consented_at
 * @property Carbon|null $completed_at
 * @property string|null $failure_code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiRequest extends Model
{
    /** @use HasFactory<AiRequestFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'task_id', 'task_file_id', 'purpose', 'provider', 'model', 'status', 'input_hash', 'result', 'consented_at', 'completed_at', 'failure_code'];

    protected function casts(): array
    {
        return ['result' => 'array', 'consented_at' => 'datetime', 'completed_at' => 'datetime'];
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
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<TaskFile, $this>
     */
    public function taskFile(): BelongsTo
    {
        return $this->belongsTo(TaskFile::class);
    }
}
