<?php

namespace App\Comms\Linking;

/**
 * The quote check every stage shares: a quote must appear word for word in
 * the text it cites, after normalizing line endings, `>` reply prefixes and
 * runs of whitespace (E-04). No quote, no operation; no quote, no refutation.
 */
final class Quote
{
    public static function in(string $text, string $quote): bool
    {
        if (trim($quote) === '') {
            return false;
        }

        if (str_contains($text, $quote)) {
            return true;
        }

        $needle = self::normalized($quote);

        return $needle !== '' && str_contains(self::normalized($text), $needle);
    }

    /**
     * Whether the quote appears in any of the texts.
     *
     * @param  iterable<string>  $texts
     */
    public static function inAny(iterable $texts, string $quote): bool
    {
        foreach ($texts as $text) {
            if (self::in($text, $quote)) {
                return true;
            }
        }

        return false;
    }

    public static function normalized(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/^[ \t]*>+[ \t]?/mu', '', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }
}
