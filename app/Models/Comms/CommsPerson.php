<?php

namespace App\Models\Comms;

use App\Comms\People\Relation;
use App\Models\User;
use Database\Factories\Comms\CommsPersonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'display_name',
    'relation',
    'importance_weight',
    'team',
    'role',
    'is_bot',
    'merged_into_id',
])]
class CommsPerson extends Model
{
    /** @use HasFactory<CommsPersonFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'relation' => Relation::class,
            'importance_weight' => 'integer',
            'is_bot' => 'boolean',
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
     * @return HasMany<CommsIdentity, $this>
     */
    public function identities(): HasMany
    {
        return $this->hasMany(CommsIdentity::class, 'person_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }
}
