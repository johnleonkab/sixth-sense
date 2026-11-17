<?php

namespace App\Models\Comms;

use App\Comms\Intents\IntentLinkKind;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'from_intent_id', 'to_intent_id', 'kind'])]
class CommsIntentLink extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => IntentLinkKind::class,
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
     * The intent that is waiting.
     *
     * @return BelongsTo<CommsIntent, $this>
     */
    public function fromIntent(): BelongsTo
    {
        return $this->belongsTo(CommsIntent::class, 'from_intent_id');
    }

    /**
     * The intent it is waiting on.
     *
     * @return BelongsTo<CommsIntent, $this>
     */
    public function toIntent(): BelongsTo
    {
        return $this->belongsTo(CommsIntent::class, 'to_intent_id');
    }
}
