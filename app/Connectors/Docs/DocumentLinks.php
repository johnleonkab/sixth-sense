<?php

namespace App\Connectors\Docs;

/**
 * Finds links to Google Docs in free text (emails, event descriptions).
 */
class DocumentLinks
{
    private const PATTERN = '~https://docs\.google\.com/document/(?:u/\d+/)?d/([A-Za-z0-9_-]{20,})~';

    /**
     * @return list<array{id: string, url: string}>
     */
    public static function in(?string $text): array
    {
        if (blank($text) || ! preg_match_all(self::PATTERN, $text, $matches, PREG_SET_ORDER)) {
            return [];
        }

        return array_values(collect($matches)
            ->unique(fn (array $match): string => $match[1])
            ->map(fn (array $match): array => ['id' => $match[1], 'url' => "https://docs.google.com/document/d/{$match[1]}/edit"])
            ->all());
    }

    public static function idFrom(string $reference): ?string
    {
        if (preg_match(self::PATTERN, $reference, $match)) {
            return $match[1];
        }

        return preg_match('~^[A-Za-z0-9_-]{20,}$~', $reference) ? $reference : null;
    }
}
