<?php

namespace App\Sense;

use App\Connectors\GitHub\GithubContents;
use App\Models\GithubRepository;

/**
 * Applies the repository's type rules to one file, loading types/{type}.yml
 * when the file declares a type.
 */
class FileRules
{
    public function __construct(private GithubContents $contents) {}

    public function violation(GithubRepository $repository, string $path, string $contents): ?string
    {
        return DocumentRules::violation($path, $contents, $this->spec($repository, $path, $contents));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function spec(GithubRepository $repository, string $path, string $contents): ?array
    {
        if (str_starts_with($path, 'types/')) {
            return null;
        }

        $document = DocumentRules::document($contents);
        $type = is_array($document) ? ($document['type'] ?? null) : null;

        if (! is_string($type) || $type === '') {
            return null;
        }

        $file = $this->contents->read($repository, "types/{$type}.yml");

        return is_array($file) ? DocumentRules::parse($file['content']) : null;
    }
}
