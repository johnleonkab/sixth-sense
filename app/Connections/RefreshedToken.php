<?php

namespace App\Connections;

use Carbon\CarbonImmutable;

final readonly class RefreshedToken
{
    /**
     * @param  ?string  $refreshToken  Only set when the provider rotates refresh tokens.
     * @param  ?list<string>  $scopes  Only set when the provider reports them.
     */
    public function __construct(
        public string $accessToken,
        public ?CarbonImmutable $expiresAt,
        public ?string $refreshToken = null,
        public ?array $scopes = null,
    ) {}
}
