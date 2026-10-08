<?php

namespace App\Voice;

use Carbon\CarbonImmutable;

/**
 * Everything the browser needs to open a live voice connection with a provider.
 */
final readonly class VoiceSession
{
    /**
     * @param  array<string, mixed>  $connection  Provider-specific connection settings for the browser client.
     */
    public function __construct(
        public string $provider,
        public string $token,
        public CarbonImmutable $connectBefore,
        public CarbonImmutable $expiresAt,
        public array $connection = [],
    ) {}

    /**
     * @return array{provider: string, token: string, connect_before: string, expires_at: string, connection: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'token' => $this->token,
            'connect_before' => $this->connectBefore->toIso8601String(),
            'expires_at' => $this->expiresAt->toIso8601String(),
            'connection' => $this->connection,
        ];
    }
}
