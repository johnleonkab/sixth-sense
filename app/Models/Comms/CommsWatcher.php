<?php

namespace App\Models\Comms;

use App\Models\User;
use Database\Factories\Comms\CommsWatcherFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'intent_id',
    'key',
    'condition_type',
    'condition',
    'deadline_at',
    'attempt',
    'on_fire',
    'state',
])]
class CommsWatcher extends Model
{
    /** @use HasFactory<CommsWatcherFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'condition' => 'array',
            'deadline_at' => 'immutable_datetime',
            'attempt' => 'integer',
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
     * @return BelongsTo<CommsIntent, $this>
     */
    public function intent(): BelongsTo
    {
        return $this->belongsTo(CommsIntent::class, 'intent_id');
    }
}
