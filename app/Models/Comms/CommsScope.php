<?php

namespace App\Models\Comms;

use App\Models\User;
use Database\Factories\Comms\CommsScopeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Channels the user wants ingested on top of the default (channels they
 * posted in during the last 30 days). A settings page writes this row.
 */
#[Fillable(['user_id', 'slack_channel_ids'])]
class CommsScope extends Model
{
    /** @use HasFactory<CommsScopeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'slack_channel_ids' => 'array',
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
