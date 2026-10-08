<?php

namespace App\Models;

use App\Connections\AccountStatus;
use App\Connections\Capability;
use App\Connections\Provider;
use Database\Factories\ConnectedAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An external account (Google, Slack…) a user has authorised. A user can
 * connect any number of accounts of the same provider.
 */
#[Fillable([
    'provider',
    'external_account_id',
    'email',
    'name',
    'avatar_url',
    'access_token',
    'refresh_token',
    'scopes',
    'disabled_capabilities',
    'metadata',
    'expires_at',
    'status',
    'last_refreshed_at',
    'watch_cursor',
    'watched_at',
])]
#[Hidden(['access_token', 'refresh_token'])]
class ConnectedAccount extends Model
{
    /** @use HasFactory<ConnectedAccountFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'status' => AccountStatus::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'scopes' => 'array',
            'disabled_capabilities' => 'array',
            'metadata' => 'array',
            'expires_at' => 'immutable_datetime',
            'last_refreshed_at' => 'immutable_datetime',
            'watched_at' => 'immutable_datetime',
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
     * Whether the access token is expired or about to expire.
     */
    public function accessTokenExpiresWithin(int $seconds): bool
    {
        return $this->expires_at !== null && $this->expires_at->subSeconds($seconds)->isPast();
    }

    /**
     * Whether the provider granted what this capability needs.
     */
    public function grants(Capability $capability): bool
    {
        return $capability->provider() === $this->provider
            && array_diff($capability->scopes(), $this->scopes ?? []) === [];
    }

    /**
     * Whether the capability is granted and the user has not switched it off.
     */
    public function allows(Capability $capability): bool
    {
        return $this->grants($capability)
            && ! in_array($capability->value, $this->disabled_capabilities ?? [], true);
    }

    /**
     * Whether sixth-sense may use this capability on this account right now.
     */
    public function can(Capability $capability): bool
    {
        return $this->status === AccountStatus::Active && $this->allows($capability);
    }

    /**
     * Switch a capability on or off for this account.
     */
    public function toggleCapability(Capability $capability, bool $enabled): void
    {
        $disabled = array_values(array_diff($this->disabled_capabilities ?? [], [$capability->value]));

        $this->update(['disabled_capabilities' => $enabled ? $disabled : [...$disabled, $capability->value]]);
    }

    /**
     * Scopes the provider requires that the user did not grant.
     *
     * @return list<string>
     */
    public function missingScopes(): array
    {
        return array_values(array_diff($this->provider->scopes(), $this->scopes ?? []));
    }

    /**
     * Active accounts on which sixth-sense may use this capability.
     *
     * @param  Builder<ConnectedAccount>  $query
     */
    public function scopeWithCapability(Builder $query, Capability $capability): void
    {
        $query->ofProvider($capability->provider())
            ->where('status', AccountStatus::Active);

        foreach ($capability->scopes() as $scope) {
            $query->whereJsonContains('scopes', $scope);
        }

        $query
            ->whereJsonDoesntContain('disabled_capabilities', $capability->value);
    }

    /**
     * @param  Builder<ConnectedAccount>  $query
     */
    public function scopeOfProvider(Builder $query, Provider $provider): void
    {
        $query->where('provider', $provider);
    }
}
