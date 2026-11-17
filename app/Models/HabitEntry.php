<?php

namespace App\Models;

use Database\Factories\HabitEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HabitEntry extends Model
{
    /** @use HasFactory<HabitEntryFactory> */
    use HasFactory;

    protected $fillable = ['habit_id', 'entry_date', 'note'];

    protected function casts(): array
    {
        return ['entry_date' => 'date'];
    }

    /**
     * @return BelongsTo<Habit, $this>
     */
    public function habit(): BelongsTo
    {
        return $this->belongsTo(Habit::class);
    }
}
