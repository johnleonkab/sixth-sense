<?php

namespace App\Models\Comms;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'run_date',
    'op',
    'payload',
    'quote',
    'why',
    'disposition',
    'decision_event_id',
    'applied_event_id',
])]
class CommsReconcileChange extends Model
{
    protected $table = 'comms_reconcile_changes';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'run_date' => 'date',
            'payload' => 'array',
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
