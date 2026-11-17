<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property array<string, mixed>|null $settings
 * @property string|null $ai_provider
 * @property string|null $ai_model
 * @property string|null $ai_api_key
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'ai_api_key'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return HasMany<Semester, $this>
     */
    public function semesters(): HasMany
    {
        return $this->hasMany(Semester::class);
    }

    /**
     * @return HasMany<Course, $this>
     */
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /**
     * @return HasMany<Assigner, $this>
     */
    public function assigners(): HasMany
    {
        return $this->hasMany(Assigner::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return HasMany<TaskSchedule, $this>
     */
    public function taskSchedules(): HasMany
    {
        return $this->hasMany(TaskSchedule::class);
    }

    /**
     * @return HasMany<TaskStatusEvent, $this>
     */
    public function taskStatusEvents(): HasMany
    {
        return $this->hasMany(TaskStatusEvent::class);
    }

    /**
     * @return HasMany<TaskFile, $this>
     */
    public function taskFiles(): HasMany
    {
        return $this->hasMany(TaskFile::class);
    }

    /**
     * @return HasMany<TaskSubmission, $this>
     */
    public function taskSubmissions(): HasMany
    {
        return $this->hasMany(TaskSubmission::class);
    }

    /**
     * @return HasMany<RecurrenceRule, $this>
     */
    public function recurrenceRules(): HasMany
    {
        return $this->hasMany(RecurrenceRule::class);
    }

    /**
     * @return HasMany<Habit, $this>
     */
    public function habits(): HasMany
    {
        return $this->hasMany(Habit::class);
    }

    /**
     * @return HasMany<IntegrationConnection, $this>
     */
    public function integrations(): HasMany
    {
        return $this->hasMany(IntegrationConnection::class);
    }

    /**
     * @return HasMany<AiRequest, $this>
     */
    public function aiRequests(): HasMany
    {
        return $this->hasMany(AiRequest::class);
    }

    /**
     * @return HasMany<Reminder, $this>
     */
    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'settings' => 'array',
            'ai_api_key' => 'encrypted',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
