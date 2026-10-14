<?php

namespace App\Wingman;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * The token the "Wingman para Meet" extension uses to talk to sixth-sense:
 * one per person, shown once, kept only as a hash, revocable.
 */
class ExtensionTokens
{
    private const PREFIX = 'wm_';

    /**
     * A new token, replacing the previous one (which stops working).
     */
    public function issue(User $user): string
    {
        $token = self::PREFIX.Str::random(48);
        $user->forceFill(['wingman_token_hash' => self::hash($token), 'wingman_token_used_at' => null])->save();

        return $token;
    }

    public function revoke(User $user): void
    {
        $user->forceFill(['wingman_token_hash' => null, 'wingman_token_used_at' => null])->save();
    }

    /**
     * Whose token it is, if it is anyone's.
     */
    public function userFor(?string $token): ?User
    {
        if (blank($token) || ! str_starts_with($token, self::PREFIX)) {
            return null;
        }

        $user = User::query()->where('wingman_token_hash', self::hash($token))->first();

        // When it was last used, without writing on every request.
        if ($user !== null && ($user->wingman_token_used_at === null || $user->wingman_token_used_at->lt(now()->subMinute()))) {
            $user->forceFill(['wingman_token_used_at' => now()])->saveQuietly();
        }

        return $user;
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
