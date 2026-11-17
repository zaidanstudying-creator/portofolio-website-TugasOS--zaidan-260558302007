<?php

namespace App\Models;

use Database\Factories\AssignerRoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['assigner_id', 'name'])]
class AssignerRole extends Model
{
    /** @use HasFactory<AssignerRoleFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Assigner, $this>
     */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(Assigner::class);
    }
}
