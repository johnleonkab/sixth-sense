<?php

namespace App\Models;

use Database\Factories\SceneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One compound request shown on screen while it runs. Depth is two:
 * a read, then the proposals that depend on that read.
 */
#[Fillable(['user_id', 'inbox_item_id', 'type', 'status', 'origin', 'input', 'canvas'])]
class Scene extends Model
{
    /** @use HasFactory<SceneFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input' => 'array',
            'canvas' => 'array',
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
    public function alert(): BelongsTo
    {
        return $this->belongsTo(InboxItem::class, 'inbox_item_id');
    }

    /**
     * @return HasMany<SceneStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(SceneStep::class);
    }
}
