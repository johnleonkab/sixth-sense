<?php

namespace App\Models\Comms;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'kind', 'ref', 'sources', 'active'])]
class CommsUrgentRule extends Model
{
    protected $table = 'comms_urgent_rules';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sources' => 'array',
            'active' => 'boolean',
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
