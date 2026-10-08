<?php

namespace App\Connectors\Chat;

use Illuminate\Support\Str;

/**
 * How well a spoken name ("marta", "el canal de producto", "Jhon") matches
 * a name in a chat workspace. Accent and case-insensitive; every word said
 * must match a word of the name, exactly, as its beginning, or with a typo
 * of the kind transcription makes.
 */
final class NameMatch
{
    public const NONE = 0;

    /** Words that are said around a name but are never part of it. */
    private const FILLER = ['el', 'la', 'los', 'las', 'de', 'del', 'con', 'y', 'a', 'al', 'canal', 'chat', 'grupo', 'conversacion', 'privado', 'mensaje', 'directo', 'md'];

    /**
     * 0 when it does not match; the higher, the closer.
     */
    public static function score(string $query, string ...$names): int
    {
        $query = self::normalise($query);
        $words = array_values(array_diff(self::words($query), self::FILLER)) ?: self::words($query);

        if ($words === []) {
            return self::NONE;
        }

        $best = self::NONE;

        foreach (array_filter($names) as $name) {
            $name = self::normalise($name);

            if ($name === implode(' ', $words) || $name === $query) {
                return 100;
            }

            $best = max($best, self::wordsScore($words, self::words($name)), strlen($query) >= 3 && str_contains($name, $query) ? 10 : self::NONE);
        }

        return $best;
    }

    public static function normalise(?string $value): string
    {
        return Str::lower(Str::ascii(trim((string) $value)));
    }

    /**
     * @param  list<string>  $said
     * @param  list<string>  $name
     */
    private static function wordsScore(array $said, array $name): int
    {
        $total = 0;

        foreach ($said as $word) {
            $scores = array_map(fn (string $part): int => self::wordScore($word, $part), $name);
            $best = $scores === [] ? 0 : max($scores);

            if ($best === 0) {
                return self::NONE;
            }

            $total += $best;
        }

        // All the words of the name said, in any order ("ruiz marta").
        return $total + (count($said) >= count($name) ? 5 : 0);
    }

    private static function wordScore(string $said, string $part): int
    {
        return match (true) {
            $said === $part => 20,
            strlen($said) >= 2 && str_starts_with($part, $said) => 12,
            strlen($said) >= 4 && self::isTypo($said, $part) => 8,
            default => 0,
        };
    }

    /**
     * One letter wrong, missing or swapped ("Jhon" for "John"); two in long words.
     */
    private static function isTypo(string $said, string $part): bool
    {
        $distance = levenshtein($said, $part);

        return $distance <= (strlen($said) >= 7 ? 2 : 1)
            || ($distance === 2 && strlen($said) === strlen($part) && count_chars($said, 1) === count_chars($part, 1));
    }

    /**
     * "producto-ventas" → ["producto", "ventas"].
     *
     * @return list<string>
     */
    private static function words(string $value): array
    {
        return array_values(array_filter(preg_split('/[^a-z0-9]+/', $value) ?: []));
    }
}
