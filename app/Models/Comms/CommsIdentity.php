<?php

namespace App\Models\Comms;

use App\Comms\Events\Source;
use App\Models\User;
use Database\Factories\Comms\CommsIdentityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'person_id', 'source', 'external_id', 'email', 'handle'])]
class CommsIdentity extends Model
{
    /** @use HasFactory<CommsIdentityFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => Source::class,
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
     * @return BelongsTo<CommsPerson, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(CommsPerson::class, 'person_id');
    }
}
