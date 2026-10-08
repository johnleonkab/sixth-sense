<?php

namespace App\Connectors\Docs;

use Carbon\CarbonImmutable;

/**
 * A document as listed in search results.
 */
final readonly class DocumentSummary
{
    /**
     * @param  ?array{name: ?string, email: ?string}  $owner
     * @param  ?array{name: ?string, email: ?string}  $lastModifiedBy
     */
    public function __construct(
        public string $ref,
        public string $account,
        public string $title,
        public ?array $owner,
        public CarbonImmutable $createdAt,
        public CarbonImmutable $modifiedAt,
        public ?array $lastModifiedBy = null,
        public ?string $url = null,
    ) {}
}
