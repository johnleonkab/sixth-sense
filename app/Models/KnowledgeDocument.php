<?php

namespace App\Models;

use App\Knowledge\KnowledgeSource;
use Database\Factories\KnowledgeDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One file of the work archive, one Jira issue or one Notion page, copied
 * so every agent can search it without calling the service. The listing
 * fills the title and version; the content follows when it is read.
 */
#[Fillable(['source', 'ref', 'title', 'url', 'version', 'source_updated_at', 'content', 'indexed_at', 'seen_at'])]
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
