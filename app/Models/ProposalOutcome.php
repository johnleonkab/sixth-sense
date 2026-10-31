<?php

namespace App\Models;

use Database\Factories\ProposalOutcomeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What happened to one thing the system proposed for an alert. Written only
 * through ProposalOutcomes.
 */
#[Fillable(['user_id', 'inbox_item_id', 'pending_action_id', 'class', 'confidence', 'action_type', 'outcome', 'reason', 'edit_size', 'detail', 'seconds_to_outcome'])]
class ProposalOutcome extends Model
{
    /** @use HasFactory<ProposalOutcomeFactory> */
    use HasFactory;

    public const SENT = 'sent_as_is';

    public const EDITED = 'edited';

    public const UNDONE = 'undone';

    public const DISCARDED = 'discarded';

    public const RESOLVED = 'resolved';

    public const EXPIRED = 'expired';

    /** Why the person discarded an alert, chosen with one tap. */
    public const REASONS = ['not_important', 'not_for_me', 'bad_proposal'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['detail' => 'array'];
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
