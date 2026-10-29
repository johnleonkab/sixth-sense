<?php

namespace App\Models;

use Database\Factories\PushSubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One browser registered for system notifications. The endpoint is the
 * address of that browser's push service; the keys encrypt the payload.
 * Uniqueness uses a hash of that address, because MySQL cannot index the full URL.
 */
#[Fillable(['user_id', 'endpoint', 'p256dh', 'auth', 'content_encoding'])]
class PushSubscription extends Model
{
    /** @use HasFactory<PushSubscriptionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (PushSubscription $subscription): void {
            $subscription->endpoint_hash = self::hashOf((string) $subscription->endpoint);
        });
    }

    public static function hashOf(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'p256dh' => 'encrypted',
            'auth' => 'encrypted',
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
