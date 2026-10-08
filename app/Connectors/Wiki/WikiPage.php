<?php

namespace App\Connectors\Wiki;

use Carbon\CarbonImmutable;

/**
 * A page with its content as markdown: what the assistant reads and quotes
 * to edit, and what the screen renders.
 */
final readonly class WikiPage
{
    /**
     * @param  ?string  $publicUrl  Set when the page is published to the web.
     */
    public function __construct(
        public string $ref,
        public string $title,
        public ?string $icon,
        public ?string $url,
        public ?string $publicUrl,
        public string $markdown,
        public string $workspace,
        public ?CarbonImmutable $editedAt = null,
        public bool $truncated = false,
    ) {}

    /**
     * Character ranges where `$find` appears in the markdown.
     *
     * @return list<int> Byte offsets.
     */
    public function locate(string $find): array
    {
        if ($find === '') {
            return [];
        }

        $offsets = [];

        for ($offset = strpos($this->markdown, $find); $offset !== false; $offset = strpos($this->markdown, $find, $offset + 1)) {
            $offsets[] = $offset;
        }

        return $offsets;
    }
}
