<?php

namespace App\Models;

use App\Voice\Tools\Canvas;
use Database\Factories\SavedViewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A composed view the person saved under a name ("mi lunes"). Its data is
 * the one it had when saved.
 */
#[Fillable(['name', 'title', 'source', 'ui', 'items', 'opened_at'])]
class SavedView extends Model
{
    /** @use HasFactory<SavedViewFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'items' => 'array',
            'opened_at' => 'immutable_datetime',
        ];
    }

    public function canvas(): Canvas
    {
        return new Canvas('openui.view', [
            'title' => $this->title,
            'source' => $this->source,
            'ui' => $this->ui,
            'items' => $this->items ?? [],
            'saved_as' => $this->name,
            'saved_at' => $this->updated_at?->toIso8601String(),
        ], 'wide');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
