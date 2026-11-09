<?php

namespace App\Comms\Intents;

use App\Comms\Context\ContextProjector;
use App\Comms\Events\EventType;
use App\Comms\Notify\Notifier;
use App\Comms\Playbooks\CloseTheLoop;
use App\Comms\Playbooks\PlaybookRunner;
use App\Comms\Playbooks\Runs;
use App\Comms\Watchers\ClockHandler;
use App\Comms\Watchers\IntentWatcher;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;

/**
 * Rebuilds intents from stored results. It does not call a model.
 */
class IntentReplay
{
    public function __construct(
        private IntentStateMachine $machine,
        private Archive $archive,
        private IntentWatcher $watchers,
        private Notifier $notifier,
        private ClockHandler $clock,
        private PlaybookRunner $playbooks,
        private Runs $runs,
        private CloseTheLoop $close,
        private ContextProjector $context,
    ) {}

    public function apply(CommsEvent $event): void
    {
        match ($event->type) {
            EventType::MessageFlagged => $this->flag($event),
            EventType::StateApplied => $this->applied($event),
            EventType::UserConfigured => $this->archive->apply($event),
            EventType::ContextCorrected, EventType::ContextObserved, EventType::ContextInferred => $this->context->apply($event),
            EventType::UserCorrected => $this->machine->correct($event),
            EventType::ClockDue, EventType::ClockStale, EventType::ClockDigest, EventType::ClockLookahead => $this->clock->apply($event),
            EventType::SourceChanged => $this->sourced($event),
            EventType::UserAnswered => $this->answered($event),
            EventType::ModelDecided => $this->playbooks->replayDecision($event),
            default => null,
        };
    }

    private function sourced(CommsEvent $event): void
    {
        $this->runs->resume($event);
        $this->close->source($event);
    }

    private function applied(CommsEvent $event): void
    {
        $this->machine->replay($event);

        if ($event->user === null) {
            return;
        }

        foreach ($event->normalized['results'] ?? [] as $result) {
            if (! is_array($result) || ($result['status'] ?? '') !== 'accepted' || ! is_string($result['intent_key'] ?? null)) {
                continue;
            }

            $intent = CommsIntent::query()->whereBelongsTo($event->user)->where('key', $result['intent_key'])->first();

            if ($intent === null) {
                continue;
            }

            $this->watchers->sync($event->user, $intent);
            $this->watchers->check($event->user, $intent);
        }

        $this->notifier->onState($event->user, $event);
    }

    private function answered(CommsEvent $event): void
    {
        $this->notifier->applyAnswer($event);
        $this->playbooks->answered($event);
    }

    private function flag(CommsEvent $event): void
    {
        if ($event->user === null) {
            return;
        }

        $message = CommsMessage::query()
            ->whereBelongsTo($event->user)
            ->where('source', $event->source)
            ->where('external_id', $event->external_id)
            ->first();

        if ($message === null) {
            return;
        }

        $normalized = $event->normalized ?? [];
        $flags = $message->flags ?? [];
        $flags['label'] = $normalized['label'] ?? ($flags['label'] ?? null);
        $flags['urgent'] = (bool) ($normalized['urgent'] ?? false);
        $flags['sensitive'] = (bool) ($normalized['sensitive'] ?? false);
        $flags['noise'] = (bool) ($normalized['noise'] ?? false);
        $flags['cc_only'] = (bool) ($normalized['cc_only'] ?? false);
        $message->flags = $flags;
        $message->save();
    }
}
