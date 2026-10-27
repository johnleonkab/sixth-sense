<?php

namespace App\Models;

use App\Alerts\AlertSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message the inbox has already taken, whether or not it became an intent.
 * Watchers can hand the same message over again; this is how it is recognised.
 */
#[Fillable(['user_id', 'source', 'external_id'])]
class SeenMessage extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => AlertSource::class,
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
