<?php

namespace App\Alerts\Intents;

/**
 * What the thread looks like when a Slack message is triaged.
 */
final readonly class SlackThreadState
{
    public function __construct(
        public string $place,
        public ?string $channel,
        public bool $directed,
        public bool $openedBySelf,
        public bool $alreadyAnswered,
        public ?string $answeredBy,
        public ?string $fromName = null,
        public string $ask = '',
    ) {}

    public function prompt(): string
    {
        $lines = [
            "Sitio: {$this->place}.",
            $this->directed ? 'Va dirigido a la persona.' : 'No va dirigido a ella.',
            $this->openedBySelf ? 'El hilo lo abrió ella.' : 'El hilo no lo abrió ella.',
            $this->alreadyAnswered
                ? 'Alguien ya respondió'.(filled($this->answeredBy) ? " ({$this->answeredBy})" : '').'.'
                : 'Nadie ha respondido todavía.',
        ];

        if ($this->channel !== null) {
            $lines[] = "Canal: {$this->channel}.";
        }

        return implode("\n", $lines);
    }
}
