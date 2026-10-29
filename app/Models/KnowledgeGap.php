<?php

namespace App\Models;

use App\Knowledge\Gaps\GapKind;
use App\Knowledge\Gaps\GapStatus;
use Database\Factories\KnowledgeGapFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something the system does not know and asks the person about once. The
 * answer becomes memory every agent reads: in the knowledge base, in the
 * assistant's instructions and, every night, in the work archive.
 */
#[Fillable(['kind', 'subject_key', 'subject', 'question', 'context', 'status', 'answer', 'priority', 'next_occurrence_at', 'inbox_item_id', 'asked_at', 'answered_at'])]
class KnowledgeGap extends Model
{
    /** @use HasFactory<KnowledgeGapFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => GapKind::class,
            'status' => GapStatus::class,
            'context' => 'array',
            'next_occurrence_at' => 'immutable_datetime',
            'asked_at' => 'immutable_datetime',
            'answered_at' => 'immutable_datetime',
        ];
    }

    /**
     * The answer as one line of memory: what it is about, then what the person said.
     */
    public function memory(): string
    {
        return "{$this->kind->label()} «{$this->subject}»: ".trim((string) $this->answer);
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
