<?php

namespace App\Models\Comms;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Cached embedding for one text and model. Outside the event log.
 */
#[Fillable(['hash', 'model', 'vector'])]
class CommsEmbedding extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vector' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
