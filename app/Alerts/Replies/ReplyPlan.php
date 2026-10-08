<?php

namespace App\Alerts\Replies;

/**
 * How the reply engine proposes to resolve an alert.
 */
final readonly class ReplyPlan
{
    public const AUDIENCES = ['sender', 'all', 'thread', 'conversation'];

    /**
     * @param  string  $summary  What to do, in one sentence to say out loud: "Responder a todos que lo revisas antes de las 12."
     * @param  ?array{to: string, text: string}  $reply  To: sender, all (everyone in the email), thread or conversation (Slack).
     * @param  list<string>  $otherActions  Anything else to do, e.g. "Crear un evento el jueves a las 10 con Julián".
     * @param  ?string  $question  What the engine could not decide and the person should say.
     */
    public function __construct(
        public string $summary,
        public ?array $reply = null,
        public array $otherActions = [],
        public ?string $question = null,
    ) {}

    /**
     * @return array{summary: string, reply: ?array{to: string, text: string}, other_actions: list<string>, question: ?string}
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'reply' => $this->reply,
            'other_actions' => $this->otherActions,
            'question' => $this->question,
        ];
    }
}
