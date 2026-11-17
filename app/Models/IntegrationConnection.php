<?php

namespace App\Models;

use Database\Factories\IntegrationConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string|null $external_user_id
 * @property string|null $account_label
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $expires_at
 * @property array<string>|null $scopes
 * @property string $status
 * @property Carbon|null $last_synced_at
 * @property string|null $last_error
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Hidden(['access_token', 'refresh_token'])]
class IntegrationConnection extends Model
{
    /** @use HasFactory<IntegrationConnectionFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'provider', 'external_user_id', 'account_label', 'access_token', 'refresh_token', 'expires_at', 'scopes', 'status', 'last_synced_at', 'last_error', 'settings'];

    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'expires_at' => 'datetime', 'scopes' => 'array', 'last_synced_at' => 'datetime', 'settings' => 'array'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ExternalResourceMapping, $this>
     */
    public function mappings(): HasMany
    {
        return $this->hasMany(ExternalResourceMapping::class);
    }

    /**
     * @return HasMany<SyncEvent, $this>
     */
    public function syncEvents(): HasMany
    {
        return $this->hasMany(SyncEvent::class);
    }
}
