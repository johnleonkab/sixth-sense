<?php

namespace App\Comms\Linking;

/**
 * Reads the critic's answer (agent-definitions 3.4). A `refuted` counts only
 * with a quote found in the raw messages or the dossier; one without is
 * ignored and reported back so it can be logged. `cannot_tell` passes.
 */
final readonly class CriticVerdict
{
    /**
     * @param  list<string>  $refutedBy  Questions refuted with a valid quote.
     * @param  list<string>  $ignored  Questions refuted without a valid quote.
     */
    public function __construct(
        public bool $refuted,
        public array $refutedBy,
        public array $ignored,
    ) {}

    /**
     * @param  array<string, mixed>  $output  Schemas::critic() output.
     * @param  list<string>  $evidence  The raw message texts of the segment, and the dossier.
     */
    public static function read(array $output, array $evidence): self
    {
        $refutedBy = [];
        $ignored = [];

        foreach (['addressed_to_user', 'already_done', 'real_ask'] as $question) {
            $answer = $output[$question] ?? null;

            if (! is_array($answer) || ($answer['verdict'] ?? null) !== 'refuted') {
                continue;
            }

            if (Quote::inAny($evidence, (string) ($answer['quote'] ?? ''))) {
                $refutedBy[] = $question;
            } else {
                $ignored[] = $question;
            }
        }

        return new self($refutedBy !== [], $refutedBy, $ignored);
    }
}
