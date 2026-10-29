<?php

namespace App\Ai;

/**
 * What a model answered, and what it took.
 */
final readonly class Answer
{
    public function __construct(
        public string $text,
        public int $inputTokens = 0,
        public int $cachedTokens = 0,
        public int $outputTokens = 0,
        public int $reasoningTokens = 0,
    ) {}

    /**
     * The answer as JSON, if it is a JSON object (tolerating a ```json fence around it).
     *
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        $text = trim($this->text);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $text, $fenced)) {
            $text = $fenced[1];
        }

        $decoded = json_decode($text, true);

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }
}
