<?php

namespace App\Models\Comms;

use App\Models\User;
use Database\Factories\Comms\CommsIntentParticipantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'intent_id', 'person_id', 'role'])]
class CommsIntentParticipant extends Model
{
    /** @use HasFactory<CommsIntentParticipantFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<CommsIntent, $this>
     */
    public function intent(): BelongsTo
    {
        return $this->belongsTo(CommsIntent::class, 'intent_id');
    }

    /**
     * @return BelongsTo<CommsPerson, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(CommsPerson::class, 'person_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
