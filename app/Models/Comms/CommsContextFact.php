<?php

namespace App\Models\Comms;

use App\Comms\Context\FactOrigin;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'kind',
    'subject',
    'attribute',
    'value',
    'audiences',
    'origin',
    'confidence',
    'refs',
    'status',
    'sensitive',
    'event_id',
    'value_hash',
    'confirmed_at',
    'last_used_at',
    'uses',
])]
class CommsContextFact extends Model
{
    protected $table = 'comms_context_facts';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'array',
            'audiences' => 'array',
            'origin' => FactOrigin::class,
            'confidence' => 'float',
            'refs' => 'array',
            'sensitive' => 'boolean',
            'confirmed_at' => 'datetime',
            'last_used_at' => 'datetime',
            'uses' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<CommsEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(CommsEvent::class, 'event_id');
    }

    /**
     * @return HasMany<CommsContextUse, $this>
     */
    public function usesRecorded(): HasMany
    {
        return $this->hasMany(CommsContextUse::class, 'fact_id');
    }

    public function text(): string
    {
        $value = $this->value;

        if (is_string($value['text'] ?? null) && $value['text'] !== '') {
            return $value['text'];
        }

        if (is_scalar($value['value'] ?? null)) {
            return (string) $value['value'];
        }

        return '';
    }
}
