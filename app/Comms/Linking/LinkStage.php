<?php

namespace App\Comms\Linking;

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Models\ModelAnswerKind;
use App\Comms\Models\ModelRouter;
use App\Comms\People\Relation;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsLinkDecision;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsSegment;
use App\Models\Comms\CommsTopicAlias;
use App\Models\Comms\CommsTopicSearch;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Three linker runs, then the decision. Candidates are shuffled per run.
 * Everything before them stays identical, so a prefix cache can hit.
 */
class LinkStage
{
    public function __construct(
        private CandidateRetriever $candidates,
        private HardLinks $hardLinks,
        private ModelRouter $router,
        private EventLog $log,
    ) {}

    public function run(CommsSegment $segment): LinkOutcome
    {
        $segment->loadMissing('user');
        $user = $segment->user;
        $messages = CommsMessage::query()
            ->whereBelongsTo($user)
            ->whereIn('id', $segment->message_ids ?? [])
            ->orderBy('id')
            ->get();
        $candidates = $this->candidates->for($segment);
        $stable = $this->stable($user, $segment, $messages, $this->hardLinks->for($segment));
        $topicLines = $this->topicLines($user, $candidates->topics);
        $intentLines = $this->intentLines($user, $candidates->intents);
        $eventIds = $messages->pluck('event_id')->map(fn (mixed $id): int => (int) $id)->all();
        $answers = $this->router->runs(
            user: $user,
            task: 'link',
            input: $stable,
            schema: Schemas::link(),
            eventIds: $eventIds,
            windowId: $segment->window_id,
            inputForRun: function (int $run) use ($stable, $candidates, $segment, $topicLines, $intentLines): string {
                $topics = $this->block($this->shuffled($candidates->topics, $segment->id, $run), $topicLines);
                $intents = $this->block($this->shuffled($candidates->intents, $segment->id, $run), $intentLines);

                return $stable."\n\nCANDIDATE TOPICS\n".$topics."\n\nCANDIDATE INTENTS\n".$intents;
            },
        );
        $votes = [];

        foreach ($answers as $answer) {
            $vote = $answer->kind === ModelAnswerKind::Accepted && is_array($answer->output)
                ? LinkVote::fromOutput($answer->output, $answer->event->id)
                : LinkVote::invalid($answer->event->id);
            $votes[] = $this->known($vote, $candidates);
        }

        $outcome = (new LinkDecision)->decide($votes, $candidates->ranking);
        $this->store($user, $segment, $outcome, $eventIds);

        return $outcome;
    }

    /**
     * @param  Collection<int, CommsMessage>  $messages
     * @param  list<string>  $hardLinks
     */
    private function stable(User $user, CommsSegment $segment, Collection $messages, array $hardLinks): string
    {
        $self = CommsPerson::query()->whereBelongsTo($user)->where('relation', Relation::Self)->first();
        $who = $self instanceof CommsPerson
            ? 'person:'.$self->id.' '.$this->plain((string) $self->display_name)
            : $this->plain((string) $user->name);
        $lines = [$who === '' ? 'person:0' : $who];

        foreach ($messages as $message) {
            $lines[] = '<source id="m'.$message->id.'"'
                .' author="person:'.$message->author_person_id.'"'
                .' at="'.$message->occurred_at->toIso8601String().'">'
                .$this->escape($message->text)
                .'</source>';
        }

        return implode("\n", [
            'USER',
            $lines[0],
            '',
            'SEGMENT',
            'restated: '.$this->plain((string) $segment->restated),
            implode("\n", array_slice($lines, 1)),
            '',
            'HARD LINKS',
            $hardLinks === [] ? '(none)' : implode("\n", $hardLinks),
            '',
            'ASK PATTERNS',
            '(none)',
            '',
            'PAST CORRECTIONS',
            '(none)',
        ]);
    }

    /**
     * @param  list<string>  $subjects
     * @return array<string, string>
     */
    private function topicLines(User $user, array $subjects): array
    {
        if ($subjects === []) {
            return [];
        }

        $documents = CommsTopicSearch::query()->whereBelongsTo($user)->whereIn('topic_subject', $subjects)->get()->keyBy('topic_subject');
        $aliases = CommsTopicAlias::query()->whereBelongsTo($user)->whereIn('topic_subject', $subjects)->orderBy('id')->get()->groupBy('topic_subject');
        $lines = [];

        foreach ($subjects as $subject) {
            $document = $this->plain((string) ($documents->get($subject)->document ?? ''));
            $names = $aliases->get($subject, collect())->pluck('alias')->map(fn (mixed $alias): string => $this->plain((string) $alias))->implode(', ');
            $lines[$subject] = 'id:'.$subject.' | '.$document.($names === '' ? '' : ' | nicknames:'.$names);
        }

        return $lines;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function intentLines(User $user, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $intents = CommsIntent::query()->whereBelongsTo($user)->whereIn('id', $ids)->with('participants')->get()->keyBy('id');
        $lines = [];

        foreach ($ids as $id) {
            $intent = $intents->get($id);

            if (! $intent instanceof CommsIntent) {
                continue;
            }

            $lines[$id] = 'id:'.$intent->id
                .' | topic:'.($intent->topic_subject ?? '')
                .' | what:'.$this->plain((string) $intent->what)
                .' | requesters:'.$this->people($intent, 'requester')
                .' | responders:'.$this->people($intent, 'responder')
                .' | deadline:'.($intent->deadline?->toDateString() ?? '')
                .' | state:'.$intent->state
                .' | last:'.$this->plain((string) $intent->last_change_summary);
        }

        return $lines;
    }

    private function people(CommsIntent $intent, string $role): string
    {
        return $intent->participants
            ->where('role', $role)
            ->pluck('person_id')
            ->map(fn (mixed $id): string => 'person:'.$id)
            ->implode(', ');
    }

    /**
     * @param  list<int|string>  $order
     * @param  array<int|string, string>  $lines
     */
    private function block(array $order, array $lines): string
    {
        $rendered = [];

        foreach ($order as $key) {
            if (isset($lines[$key])) {
                $rendered[] = $lines[$key];
            }
        }

        return $rendered === [] ? '(none)' : implode("\n", $rendered);
    }

    /**
     * @param  list<int|string>  $items
     * @return list<int|string>
     */
    private function shuffled(array $items, int $segmentId, int $run): array
    {
        $items = array_values($items);
        mt_srand(crc32($segmentId.':'.$run));
        shuffle($items);
        mt_srand();

        return $items;
    }

    private function known(LinkVote $vote, Candidates $candidates): LinkVote
    {
        if (! $vote->valid || $vote->target === null) {
            return $vote;
        }

        $alternative = $vote->alternative === null || $this->allowed($vote->alternative, $candidates);

        if ($this->allowed($vote->target, $candidates) && $alternative) {
            return $vote;
        }

        return LinkVote::invalid($vote->decisionEventId);
    }

    private function allowed(string $target, Candidates $candidates): bool
    {
        if ($target === 'new_topic' || $target === 'none') {
            return true;
        }

        if (str_starts_with($target, 'intent:')) {
            $id = substr($target, 7);

            return ctype_digit($id) && in_array((int) $id, $candidates->intents, true);
        }

        if (str_starts_with($target, 'topic:')) {
            return in_array(substr($target, 6), $candidates->topics, true);
        }

        return false;
    }

    /**
     * @param  list<int>  $eventIds
     */
    private function store(User $user, CommsSegment $segment, LinkOutcome $outcome, array $eventIds): void
    {
        $event = $this->log->append(
            user: $user,
            type: EventType::LinkDecided,
            source: Source::Model,
            externalId: 'segment:'.$segment->id,
            payload: null,
            normalized: [
                'segment_id' => $segment->id,
                'target' => $outcome->target,
                'topic_subject' => $outcome->topicSubject,
                'intent_id' => $outcome->intentId(),
                'outcome' => $outcome->outcomeColumn(),
                'certainty' => $outcome->certainty->value,
                'agreement' => $outcome->agreement,
                'alternative' => $outcome->alternative,
                'quote' => $outcome->quote,
                'new_topic_name' => $outcome->newTopicName,
                'run_event_ids' => $outcome->runEventIds,
            ],
            causation: $eventIds === [] ? null : CommsEvent::query()->whereBelongsTo($user)->find($eventIds[0]),
            occurredAt: now(),
        );

        CommsLinkDecision::query()->updateOrCreate(
            ['segment_id' => $segment->id],
            [
                'user_id' => $user->id,
                'topic_subject' => $outcome->topicSubject,
                'intent_id' => $outcome->intentId(),
                'outcome' => $outcome->outcomeColumn(),
                'certainty' => $outcome->certainty->value,
                'agreement' => $outcome->agreement,
                'alternative' => $outcome->alternative === null ? null : [$outcome->alternative],
                'quote' => $outcome->quote,
                'run_event_ids' => $outcome->runEventIds,
                'event_id' => $event->id,
            ],
        );
    }

    private function plain(string $value): string
    {
        return trim(str_replace(["\r", "\n"], ' ', $value));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
