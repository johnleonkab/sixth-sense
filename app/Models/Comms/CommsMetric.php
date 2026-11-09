<?php

namespace App\Models\Comms;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'day',
    'messages_in',
    'dropped_by_rule',
    'dropped_by_model',
    'intents_opened',
    'intents_settled',
    'uncertain_links',
    'corrections',
    'misses',
])]
class CommsMetric extends Model
{
    protected $table = 'comms_metrics_daily';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day' => 'date',
            'corrections' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
