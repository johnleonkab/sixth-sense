<?php

namespace App\Models;

use App\Knowledge\KnowledgeSource;
use Database\Factories\KnowledgeDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One file of the work archive, one Jira issue, one Notion page or one
 * meeting's notes, indexed so every agent can find it without calling the
 * service. It keeps no text: the title, the link, the distinct words, and a
 * short summary only while it is among the most used.
 */
#[Fillable(['source', 'ref', 'title', 'url', 'version', 'source_updated_at', 'terms', 'summary', 'summarized_version', 'views', 'last_viewed_at', 'indexed_at', 'seen_at'])]
class KnowledgeDocument extends Model
{
    /** @use HasFactory<KnowledgeDocumentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => KnowledgeSource::class,
            'source_updated_at' => 'immutable_datetime',
            'indexed_at' => 'immutable_datetime',
            'seen_at' => 'immutable_datetime',
            'last_viewed_at' => 'immutable_datetime',
            'views' => 'integer',
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
