<?php

namespace App\Models;

use Database\Factories\SyncEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncEvent extends Model
{
    /** @use HasFactory<SyncEventFactory> */
    use HasFactory;

    protected $fillable = ['integration_connection_id', 'provider', 'direction', 'action', 'status', 'message', 'context'];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }

    /**
     * @return BelongsTo<IntegrationConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }
}
