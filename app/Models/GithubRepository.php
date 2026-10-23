<?php

namespace App\Models;

use App\Connections\Capability;
use Database\Factories\GithubRepositoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A GitHub repository a connected account can see. The person chooses which
 * ones sixth-sense uses, and which of those may have their code updated.
 */
#[Fillable([
    'github_id',
    'owner',
    'name',
    'full_name',
    'is_private',
    'default_branch',
    'can_push',
    'included',
    'writes_code',
    'is_work',
])]
class GithubRepository extends Model
{
    /** @use HasFactory<GithubRepositoryFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'github_id' => 'integer',
            'is_private' => 'boolean',
            'can_push' => 'boolean',
            'included' => 'boolean',
            'writes_code' => 'boolean',
            'is_work' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ConnectedAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class, 'connected_account_id');
    }

    /**
     * Whether sixth-sense may commit to this repository: the person included
     * it, allowed code updates, and GitHub itself lets this account push.
     */
    public function mayUpdateCode(): bool
    {
        return $this->included
            && $this->writes_code
            && $this->can_push
            && $this->account->can(Capability::GithubWrite);
    }
}
