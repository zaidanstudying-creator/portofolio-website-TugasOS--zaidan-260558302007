<?php

namespace App\Models;

use Database\Factories\ExternalResourceMappingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalResourceMapping extends Model
{
    /** @use HasFactory<ExternalResourceMappingFactory> */
    use HasFactory;

    protected $fillable = ['integration_connection_id', 'resource_type', 'internal_id', 'external_id', 'external_container_id', 'sync_hash', 'last_synced_at', 'status'];

    protected function casts(): array
    {
        return ['last_synced_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<IntegrationConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }
}
