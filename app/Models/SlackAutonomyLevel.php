<?php

namespace App\Models;

use App\Alerts\Intents\AutonomyLevel;
use App\Alerts\Intents\SlackAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How far one Slack action may go for this person. Missing row means draft.
 */
#[Fillable(['user_id', 'action', 'level'])]
class SlackAutonomyLevel extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => SlackAction::class,
            'level' => AutonomyLevel::class,
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
