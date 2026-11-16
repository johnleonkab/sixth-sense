<?php

namespace App\Models\Comms;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'intent_id', 'seen_at'])]
class CommsCardView extends Model
{
    protected $table = 'comms_card_views';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seen_at' => 'immutable_datetime',
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
     * @return BelongsTo<CommsIntent, $this>
     */
    public function intent(): BelongsTo
    {
        return $this->belongsTo(CommsIntent::class, 'intent_id');
    }
}
