<?php

namespace App\Models\Comms;

use App\Comms\Gate\ActionClass;
use App\Comms\Gate\PolicyOutcome;
use App\Models\User;
use Database\Factories\Comms\CommsPolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'action_class', 'conditions', 'outcome'])]
class CommsPolicy extends Model
{
    /** @use HasFactory<CommsPolicyFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action_class' => ActionClass::class,
            'conditions' => 'array',
            'outcome' => PolicyOutcome::class,
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
