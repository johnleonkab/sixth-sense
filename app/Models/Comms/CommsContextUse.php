<?php

namespace App\Models\Comms;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['fact_id', 'audience', 'model_call_ref'])]
class CommsContextUse extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'comms_context_uses';

    /**
     * @return BelongsTo<CommsContextFact, $this>
     */
    public function fact(): BelongsTo
    {
        return $this->belongsTo(CommsContextFact::class, 'fact_id');
    }
}
