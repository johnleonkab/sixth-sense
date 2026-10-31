<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something learned from the person's alerts: what the reply agent found
 * while working out an intent, or what a correction taught (an edit, a
 * discard with its reason, a person picked, a length changed). Each belongs
 * to the layer it improves and, when it is about someone, to that person.
 */
#[Fillable(['user_id', 'inbox_item_id', 'proposal_outcome_id', 'layer', 'about', 'topic', 'body', 'uses', 'last_used_at'])]
class IntentLesson extends Model
{
    /** The layers a lesson can improve: none means all of them. */
    public const LAYERS = [
        'judge' => 'Importancia',
        'triage' => 'A quién le toca',
        'classifier' => 'Qué se pide',
        'reply' => 'Respuestas',
        'scheduler' => 'Agenda',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
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
     * @return BelongsTo<InboxItem, $this>
     */
    public function inboxItem(): BelongsTo
    {
        return $this->belongsTo(InboxItem::class);
    }
}
