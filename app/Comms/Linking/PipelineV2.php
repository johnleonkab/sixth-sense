<?php

namespace App\Comms\Linking;

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Intents\IntentStateMachine;
use App\Comms\Intents\TriageResult;
use App\Comms\Notify\Notifier;
use App\Comms\Playbooks\PlaybookRunner;
use App\Comms\Watchers\IntentWatcher;
use App\Events\IntentsUpdated;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsLinkDecision;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsSegment;
use App\Models\Comms\CommsWindow;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Segments a settled window, then links, extracts, checks and criticises
 * each exchange before the state machine applies what survived.
 */
class PipelineV2
{
    public function __construct(
        private Segmenter $segmenter,
        private LinkStage $links,
        private ExtractStage $extractor,
        private Checks $checks,
        private CriticStage $critic,
        private SegmentRouter $router,
        private IntentStateMachine $machine,
        private EventLog $log,
        private IntentWatcher $watchers,
        private Notifier $notifier,
        private PlaybookRunner $playbooks,
    ) {}

    public function process(CommsEvent $event): void
    {
        $user = $event->user;
        $window = $user === null ? null : $this->window($user, $event);

        if ($user === null || $window === null) {
            return;
        }

        foreach ($this->segmenter->run($window) as $segment) {
            $this->apply($user, $segment);
        }
    }

    private function window(User $user, CommsEvent $event): ?CommsWindow
    {
        $id = $event->normalized['window_id'] ?? null;

        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return null;
        }

        return CommsWindow::query()->whereBelongsTo($user)->find((int) $id);
    }

    private function apply(User $user, CommsSegment $segment): void
    {
        $outcome = $this->links->run($segment);

        if ($outcome->isNone()) {
            return;
        }

        $reviews = $this->critic->run(
            $segment,
            $this->checks->run($segment, $this->extractor->run($segment, $outcome)),
            $outcome->topicSubject,
        );
        $messages = CommsMessage::query()
            ->whereBelongsTo($user)
            ->whereIn('id', $segment->message_ids ?? [])
            ->orderBy('id')
            ->get();
        $linkEventId = CommsLinkDecision::query()->where('segment_id', $segment->id)->value('event_id');
        $groups = [];

        foreach ($reviews as $review) {
            $route = $this->router->route($outcome->certainty, $review->operation, $review->refuted);
            $raw = $review->operation->operation;
            $quote = (string) ($raw['quote'] ?? '');
            $message = $this->messageFor($messages, $quote);

            if (! $message instanceof CommsMessage) {
                continue;
            }

            if ($route->recordSignal) {
                $this->machine->effect($user, $message, [
                    'op' => 'signal',
                    'status' => 'accepted',
                    'quote' => $quote,
                    'summary' => (string) ($raw['summary'] ?? $raw['what'] ?? $quote),
                    'topic_subject' => $outcome->topicSubject,
                    'intent_key' => isset($raw['intent_id']) ? (string) $raw['intent_id'] : null,
                    'importance' => 0,
                ], null, false);
            }

            if (! $route->apply) {
                continue;
            }

            $groups[$message->id]['message'] = $message;
            $groups[$message->id]['items'][] = [
                'review' => $review,
                'route' => $route,
                'prepared' => $this->prepare($raw),
            ];
        }

        $accepted = false;

        foreach ($groups as $group) {
            if ($this->commit($user, $segment, $outcome, $group, $linkEventId === null ? null : (int) $linkEventId)) {
                $accepted = true;
            }
        }

        if ($accepted) {
            IntentsUpdated::dispatch($user);
        }
    }

    /**
     * @param  array{message: CommsMessage, items: list<array{review: CriticReview, route: RouteDecision, prepared: array<string, mixed>}>}  $group
     */
    private function commit(User $user, CommsSegment $segment, LinkOutcome $outcome, array $group, ?int $linkEventId): bool
    {
        $message = $group['message'];
        $judged = $this->machine->decide(
            $user,
            $message,
            array_map(fn (array $item): array => $item['prepared'], $group['items']),
            1.0,
            null,
            new TriageResult('inbox'),
        );
        $results = [];
        $playbooks = false;
        $accepted = false;

        foreach ($judged as $index => $result) {
            $item = $group['items'][$index] ?? null;

            if (is_array($item)) {
                $result['kind'] = is_string($item['prepared']['kind'] ?? null) ? $item['prepared']['kind'] : null;
                $result['steps'] = is_array($item['prepared']['steps'] ?? null) && $item['prepared']['steps'] !== []
                    ? $item['prepared']['steps']
                    : null;
                $result['unclear_fields'] = $item['review']->operation->status === AgreementStatus::UnclearFields
                    ? $item['review']->operation->unclearFields
                    : null;
                $result['possible_ask'] = $item['route']->uncertainOperation && ($result['op'] ?? '') === 'open';
                $result['topic_subject'] = $outcome->topicSubject;
                $result['segment_id'] = $segment->id;
                $result['uncertain'] = $item['route']->uncertainLink;
                $result['alternative'] = $outcome->alternative === null ? null : [$outcome->alternative];
                $result['link_event_id'] = $linkEventId;
            }

            if (($result['status'] ?? '') === 'accepted') {
                $result = $this->machine->effect($user, $message, $result, null, true);
                $accepted = true;
                $playbooks = $playbooks || (is_array($item) && $item['route']->playbooksAllowed);
            }

            $results[] = $result;
        }

        if ($results === []) {
            return false;
        }

        $cause = CommsEvent::query()->find($message->event_id);
        $applied = $this->log->append(
            user: $user,
            type: EventType::StateApplied,
            source: $message->source,
            externalId: $message->external_id,
            payload: null,
            normalized: ['results' => $results],
            causation: $cause instanceof CommsEvent ? $cause : null,
            occurredAt: $message->occurred_at,
        );

        CommsConversationLink::query()
            ->whereBelongsTo($user)
            ->where('message_id', $message->id)
            ->update(['state_event_id' => $applied->id]);

        foreach ($results as $result) {
            if (($result['status'] ?? '') !== 'accepted' || ! is_string($result['intent_key'] ?? null)) {
                continue;
            }

            $intent = CommsIntent::query()->whereBelongsTo($user)->where('key', $result['intent_key'])->first();

            if ($intent === null) {
                continue;
            }

            $intent->last_change_event_id = $applied->id;

            if (($result['op'] ?? '') === 'settle') {
                $intent->settled_event_id = $applied->id;
            }

            $intent->save();
            $this->watchers->sync($user, $intent);
            $this->watchers->check($user, $intent);
        }

        $this->notifier->onState($user, $applied);

        if ($playbooks) {
            $this->playbooks->afterApplied($user, $applied);
        }

        return $accepted;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function prepare(array $operation): array
    {
        $deadline = $operation['deadline'] ?? null;

        if (is_array($deadline)) {
            $date = $deadline['date'] ?? null;
            $text = $deadline['text'] ?? null;
            $operation['deadline'] = is_string($date) && $date !== '' ? $date : null;
            $operation['deadline_text'] = is_string($text) && $text !== '' ? $text : null;
        }

        foreach (['requesters', 'responders', 'possible_responders'] as $field) {
            if (! is_array($operation[$field] ?? null)) {
                continue;
            }

            $operation[$field] = array_values(array_filter(
                $operation[$field],
                fn (mixed $ref): bool => is_string($ref) && $ref !== '' && $ref !== 'unclear',
            ));
        }

        $operation['confidence'] = 1;

        return $operation;
    }

    /**
     * @param  Collection<int, CommsMessage>  $messages
     */
    private function messageFor(Collection $messages, string $quote): ?CommsMessage
    {
        foreach ($messages as $message) {
            if (Quote::in((string) $message->text, $quote)) {
                return $message;
            }
        }

        return $messages
            ->sort(fn (CommsMessage $left, CommsMessage $right): int => $left->occurred_at->getTimestamp() <=> $right->occurred_at->getTimestamp()
                ?: $left->id <=> $right->id)
            ->last();
    }
}
