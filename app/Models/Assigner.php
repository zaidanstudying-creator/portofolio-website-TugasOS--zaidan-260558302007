<?php

namespace App\Models;

use Database\Factories\AssignerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'name', 'title', 'institution', 'email', 'phone', 'notes'])]
class Assigner extends Model
{
    /** @use HasFactory<AssignerFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<AssignerRole, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(AssignerRole::class);
    }
}
