<?php

namespace App\Connectors\Mail;

use Illuminate\Support\Str;

/**
 * A regular expression the assistant uses to find what was said in an email.
 * Case-insensitive; if the text does not match as written, it is matched
 * again without accents, so "menciono" finds "mencionó".
 */
final readonly class MailPattern
{
    private const MAX_LENGTH = 200;

    private const EXCERPT_CONTEXT = 90;

    private function __construct(
        public string $source,
        private string $regex,
        private string $asciiRegex,
    ) {}

    /**
     * @throws InvalidMailPattern
     */
    public static function compile(string $source): self
    {
        if (mb_strlen($source) > self::MAX_LENGTH) {
            throw new InvalidMailPattern('El patrón es demasiado largo.');
        }

        $regex = self::delimit($source);

        if (@preg_match($regex, '') === false) {
            throw new InvalidMailPattern('El patrón no es una expresión regular válida.');
        }

        return new self($source, $regex, self::delimit(Str::ascii($source)));
    }

    /**
     * The first match with some context around it, or null when it does not match.
     *
     * @return ?array{before: string, match: string, after: string}
     */
    public function excerpt(string $text): ?array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        foreach ([[$this->regex, $text], [$this->asciiRegex, Str::ascii($text)]] as [$regex, $haystack]) {
            if (self::safeMatch($regex, $haystack, $match) && $match[0][0] !== '') {
                [$found, $offset] = $match[0];
                $start = mb_strlen(substr($haystack, 0, $offset));
                $length = mb_strlen($found);

                return [
                    'before' => self::trimStart(mb_substr($haystack, max(0, $start - self::EXCERPT_CONTEXT), min($start, self::EXCERPT_CONTEXT)), $start > self::EXCERPT_CONTEXT),
                    'match' => mb_substr($haystack, $start, $length),
                    'after' => self::trimEnd(mb_substr($haystack, $start + $length, self::EXCERPT_CONTEXT), mb_strlen($haystack) > $start + $length + self::EXCERPT_CONTEXT),
                ];
            }
        }

        return null;
    }

    /**
     * Where the pattern appears in the message: the body first, so the excerpt
     * adds something to the subject shown next to it; the subject otherwise.
     *
     * @return ?array{before: string, match: string, after: string}
     */
    public function excerptFor(MailMessage $message): ?array
    {
        return $this->excerpt($message->body ?? $message->snippet) ?? $this->excerpt($message->subject);
    }

    /**
     * Split text into plain and matching segments, to highlight every match.
     *
     * @return list<array{text: string, match: bool}>
     */
    public function segments(string $text): array
    {
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '200000');

        try {
            $found = @preg_match_all($this->regex, $text, $matches, PREG_OFFSET_CAPTURE);
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
        }

        if (! $found) {
            return [['text' => $text, 'match' => false]];
        }

        $segments = [];
        $cursor = 0;

        foreach ($matches[0] as [$match, $offset]) {
            if ($match === '') {
                continue;
            }

            if ($offset > $cursor) {
                $segments[] = ['text' => substr($text, $cursor, $offset - $cursor), 'match' => false];
            }

            $segments[] = ['text' => $match, 'match' => true];
            $cursor = $offset + strlen($match);
        }

        if ($cursor < strlen($text)) {
            $segments[] = ['text' => substr($text, $cursor), 'match' => false];
        }

        return $segments;
    }

    private static function delimit(string $source): string
    {
        return '~'.str_replace('~', '\~', $source).'~iu';
    }

    /**
     * Match with a backtracking budget, so a pathological pattern can not hang the request.
     *
     * @param  array<int, array{0: string, 1: int}>|null  $match
     */
    private static function safeMatch(string $regex, string $text, ?array &$match): bool
    {
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '200000');

        try {
            return @preg_match($regex, $text, $match, PREG_OFFSET_CAPTURE) === 1;
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
        }
    }

    private static function trimStart(string $text, bool $cut): string
    {
        return $cut ? '…'.ltrim((string) preg_replace('/^\S*\s/u', '', $text)) : $text;
    }

    private static function trimEnd(string $text, bool $cut): string
    {
        return $cut ? rtrim((string) preg_replace('/\s\S*$/u', '', $text)).'…' : $text;
    }
}
