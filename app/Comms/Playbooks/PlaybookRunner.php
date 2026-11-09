<?php

namespace App\Comms\Playbooks;

use App\Comms\Notify\Notifier;
use App\Comms\Playbooks\Steps\Propose;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsOwnedArea;
use App\Models\Comms\CommsProposal;
use App\Models\Comms\CommsQuestion;
use App\Models\User;

/**
 * Runs the playbook the rules picked. Replay reads stored model output
 * and does not call a model.
 */
class PlaybookRunner
{
    public function __construct(
        private RouteRules $rules,
        private AnswerFromSources $answers,
        private RebuildContext $rebuilds,
        private PrepareWork $prepare,
        private CloseTheLoop $close,
        private Propose $propose,
        private Notifier $notifier,
    ) {}

    public function afterApplied(User $user, CommsEvent $applied): void
    {
        foreach ($applied->normalized['results'] ?? [] as $result) {
            if (! is_array($result) || ($result['status'] ?? '') !== 'accepted' || ! is_string($result['intent_key'] ?? null)) {
                continue;
            }

            $intent = CommsIntent::query()->whereBelongsTo($user)->where('key', $result['intent_key'])->first();
            $message = CommsMessage::query()
                ->whereBelongsTo($user)
                ->where('source', $applied->source)
                ->where('external_id', $applied->external_id)
                ->first();

            if ($intent === null || $message === null) {
                continue;
            }

            foreach ($this->rules->keys($user, $intent, $message) as $key) {
                match ($key) {
                    'answer_from_sources' => $this->answers->run($user, $intent, $message),
                    'rebuild_context' => $this->rebuilds->run($user, $intent, $message),
                    'prepare_work' => $this->prepare->run($user, $intent, $message),
                    default => null,
                };
            }

            if (($result['op'] ?? '') === 'settle' && ($result['settle_reason'] ?? '') === 'decision') {
                $this->close->decision($user, $intent, $message, (string) ($result['quote'] ?? ''));
            }
        }
    }

    public function copied(User $user, CommsMessage $message, CommsEvent $event): void
    {
        $areas = CommsOwnedArea::query()->whereBelongsTo($user)->get();
        $haystack = mb_strtolower($message->text);

        foreach ($areas as $area) {
            $needles = array_filter([$area->ref, $area->name, ...($area->keywords ?? [])]);

            foreach ($needles as $needle) {
                if (is_string($needle) && $needle !== '' && str_contains($haystack, mb_strtolower($needle))) {
                    $this->notifier->store($user, 'signal', 'digest', null, null, 'n:cc:'.$event->id, [$event->id]);

                    return;
                }
            }
        }
    }

    public function answered(CommsEvent $event): void
    {
        $data = $event->normalized ?? [];

        if (($data['kind'] ?? '') !== 'choice' || $event->user === null || ! is_string($data['question_key'] ?? null)) {
            return;
        }

        $question = CommsQuestion::query()->whereBelongsTo($event->user)->where('key', $data['question_key'])->first();
        $intent = $question?->intent;

        if ($intent === null || CommsProposal::query()->where('intent_id', $intent->id)->exists()) {
            return;
        }

        $message = $this->messageFor($intent);

        if ($message === null) {
            return;
        }

        $text = trim((string) ($data['text'] ?? ''));

        if ($text === '') {
            return;
        }

        $this->propose->submit($event->user, $intent, $message, $text, [], facts: false);
    }

    public function replayDecision(CommsEvent $event): void
    {
        $task = $event->normalized['task'] ?? null;
        $output = $event->normalized['output'] ?? null;

        if ($event->user === null || ! is_array($output) || ! in_array($task, ['answer', 'summarize'], true)) {
            return;
        }

        $message = $event->causation_id !== null
            ? CommsMessage::query()->whereBelongsTo($event->user)->where('event_id', $event->causation_id)->first()
            : null;

        if ($message === null) {
            return;
        }

        $intent = CommsIntent::query()
            ->whereBelongsTo($event->user)
            ->whereHas('links', fn ($query) => $query->where('message_id', $message->id))
            ->first();

        if ($intent === null) {
            return;
        }

        if ($task === 'summarize' && is_string($output['summary'] ?? null)) {
            $this->rebuilds->show($event->user, $intent, $message, $output['summary']);

            return;
        }

        if ($task === 'answer') {
            $this->answers->applyStored($event->user, $intent, $message, $output);
        }
    }

    private function messageFor(CommsIntent $intent): ?CommsMessage
    {
        return CommsMessage::query()
            ->whereIn('id', $intent->links()->pluck('message_id'))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();
    }
}
