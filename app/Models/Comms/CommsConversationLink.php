<?php

namespace App\Models\Comms;

use App\Models\User;
use Database\Factories\Comms\CommsConversationLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'intent_id', 'message_id', 'operation', 'confidence', 'uncertain', 'state_event_id'])]
class CommsConversationLink extends Model
{
    /** @use HasFactory<CommsConversationLinkFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'uncertain' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<CommsIntent, $this>
     */
    public function intent(): BelongsTo
    {
        return $this->belongsTo(CommsIntent::class, 'intent_id');
    }

    /**
     * @return BelongsTo<CommsMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(CommsMessage::class, 'message_id');
    }

    /**
     * @return BelongsTo<CommsEvent, $this>
     */
    public function stateEvent(): BelongsTo
    {
        return $this->belongsTo(CommsEvent::class, 'state_event_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
