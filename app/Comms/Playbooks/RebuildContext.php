<?php

namespace App\Comms\Playbooks;

use App\Comms\Context\ContextEngine;
use App\Comms\Models\ModelAnswerKind;
use App\Comms\Playbooks\Steps\Ask;
use App\Comms\Playbooks\Steps\Resolve;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\User;
use Illuminate\Contracts\Container\Container;

/**
 * Case 4. A late reply is shown with what was asked, why, and what they say now.
 * The reply itself stays a draft until the user writes it.
 */
class RebuildContext
{
    public function __construct(
        private ContextEngine $context,
        private Ask $ask,
        private Container $container,
    ) {}

    public function run(User $user, CommsIntent $intent, CommsMessage $message): void
    {
        $lines = array_map(
            fn (array $row): string => $row['text'],
            $this->context->conversation($intent),
        );
        $answer = $this->container->make(Resolve::class)->summarize($user, $intent, $message, implode("\n", $lines));

        if ($answer->kind !== ModelAnswerKind::Accepted || ! is_string($answer->output['summary'] ?? null)) {
            return;
        }

        $this->show($user, $intent, $message, $answer->output['summary']);
    }

    public function show(User $user, CommsIntent $intent, CommsMessage $message, string $summary): void
    {
        $this->ask->open($user, $intent, $summary, ['draft' => ''], [$message->event_id]);
    }
}
