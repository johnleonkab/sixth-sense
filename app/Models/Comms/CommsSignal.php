<?php

namespace App\Models\Comms;

use App\Models\User;
use Database\Factories\Comms\CommsSignalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'message_id', 'intent_id', 'owned_area_id', 'summary', 'importance', 'shown_at'])]
class CommsSignal extends Model
{
    /** @use HasFactory<CommsSignalFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'importance' => 'integer',
            'shown_at' => 'immutable_datetime',
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
     * @return BelongsTo<CommsMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(CommsMessage::class, 'message_id');
    }
}
