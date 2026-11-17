<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\HabitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Habit extends Model
{
    /** @use HasFactory<HabitFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'description', 'target_per_week', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<HabitEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(HabitEntry::class)->orderByDesc('entry_date');
    }

    public function currentStreak(): int
    {
        $entries = $this->relationLoaded('entries') ? $this->entries : $this->entries()->get();
        $dates = $entries->pluck('entry_date')
            ->map(fn ($date): string => CarbonImmutable::parse($date)->toDateString())
            ->unique()
            ->flip();
        $cursor = CarbonImmutable::today();

        if (! $dates->has($cursor->toDateString())) {
            $cursor = $cursor->subDay();
        }

        $streak = 0;

        while ($dates->has($cursor->toDateString())) {
            $streak++;
            $cursor = $cursor->subDay();
        }

        return $streak;
    }

    public function consistency(int $days = 30): int
    {
        $start = today()->subDays($days - 1);
        $end = today();
        $startDate = $start->toDateString();
        $endDate = $end->toDateString();
        $completed = $this->relationLoaded('entries')
            ? $this->entries->filter(function (HabitEntry $entry) use ($startDate, $endDate): bool {
                $entryDate = CarbonImmutable::parse($entry->entry_date)->toDateString();

                return $entryDate >= $startDate && $entryDate <= $endDate;
            })->count()
            : $this->entries()->whereBetween('entry_date', [$start, $end])->count();
        $target = max(1, (int) ceil($this->target_per_week * $days / 7));

        return min(100, (int) round($completed / $target * 100));
    }
}
