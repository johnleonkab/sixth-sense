<?php

namespace App\Connections;

/**
 * An external account the provider has just authorised.
 */
final readonly class AuthorizedAccount
{
    /**
     * @param  list<string>  $scopes
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $externalId,
        public ?string $email,
        public ?string $name,
        public ?string $avatarUrl,
        public string $accessToken,
        public ?string $refreshToken,
        public ?int $expiresIn,
        public array $scopes,
        public array $metadata = [],
    ) {}
}
