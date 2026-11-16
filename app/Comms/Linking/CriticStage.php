<?php

namespace App\Comms\Linking;

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Models\ModelAnswerKind;
use App\Comms\Models\ModelRouter;
use App\Comms\People\Relation;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsSegment;
use App\Models\Comms\CommsTopicSearch;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * One critic run per open, settle and reopen. A refutation without a quote
 * in the messages or the dossier is ignored and logged.
 */
class CriticStage
{
    public function __construct(
        private ModelRouter $router,
        private EventLog $log,
    ) {}

    /**
     * @param  list<AgreedOperation>  $operations
     * @return list<CriticReview>
     */
    public function run(CommsSegment $segment, array $operations, ?string $topicSubject): array
    {
        $segment->loadMissing('user');
        $user = $segment->user;
        $messages = CommsMessage::query()
            ->whereBelongsTo($user)
            ->whereIn('id', $segment->message_ids ?? [])
            ->orderBy('id')
            ->get();
        $texts = $messages->map(fn (CommsMessage $message): string => (string) $message->text)->all();
        $dossier = $this->dossier($user, $topicSubject);
        $evidence = $texts;

        if ($dossier !== '') {
            $evidence[] = $dossier;
        }

        $eventIds = $messages->pluck('event_id')->map(fn (mixed $id): int => (int) $id)->all();
        $reviews = [];
        $ignored = [];

        foreach ($operations as $operation) {
            if (! SegmentRouter::criticChecks($operation->op())) {
                $reviews[] = new CriticReview($operation, false);

                continue;
            }

            $answer = $this->router->ask(
                user: $user,
                task: 'critic',
                input: $this->input($user, $operation, $messages, $dossier),
                schema: Schemas::critic(),
                eventIds: $eventIds,
                windowId: $segment->window_id,
            );
            $output = $answer->kind === ModelAnswerKind::Accepted && is_array($answer->output) ? $answer->output : null;
            $verdict = is_array($output) ? CriticVerdict::read($output, $evidence) : null;
            $reviews[] = new CriticReview($operation, $verdict?->refuted ?? false);

            foreach ($verdict?->ignored ?? [] as $question) {
                $quote = is_array($output[$question] ?? null) ? (string) ($output[$question]['quote'] ?? '') : '';
                $ignored[] = [
                    'op' => $operation->op(),
                    'status' => 'rejected',
                    'reason' => 'ignored',
                    'quote' => $quote,
                    'question' => $question,
                ];
            }
        }

        if ($ignored !== []) {
            $cause = CommsEvent::query()->find($messages->first()?->event_id);
            $newest = $messages->sortBy(fn (CommsMessage $message): int => $message->occurred_at->getTimestamp())->last();

            $this->log->append(
                user: $user,
                type: EventType::StateApplied,
                source: Source::Model,
                externalId: 'critic:'.$segment->id,
                payload: null,
                normalized: [
                    'segment_id' => $segment->id,
                    'results' => $ignored,
                ],
                causation: $cause instanceof CommsEvent ? $cause : null,
                occurredAt: $newest instanceof CommsMessage ? $newest->occurred_at : now(),
            );
        }

        return $reviews;
    }

    /**
     * @param  Collection<int, CommsMessage>  $messages
     */
    private function input(User $user, AgreedOperation $operation, Collection $messages, string $dossier): string
    {
        $self = CommsPerson::query()->whereBelongsTo($user)->where('relation', Relation::Self)->first();
        $who = $self instanceof CommsPerson
            ? 'person:'.$self->id.' '.$this->plain((string) $self->display_name)
            : $this->plain((string) $user->name);
        $sources = [];

        foreach ($messages as $message) {
            $sources[] = '<source id="m'.$message->id.'"'
                .' author="person:'.$message->author_person_id.'"'
                .' at="'.$message->occurred_at->toIso8601String().'">'
                .$this->escape($message->text)
                .'</source>';
        }

        return implode("\n", [
            'USER',
            $who === '' ? 'person:0' : $who,
            '',
            'OPERATION',
            json_encode($operation->operation, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            '',
            'MESSAGES',
            $sources === [] ? '(none)' : implode("\n", $sources),
            '',
            'DOSSIER',
            $dossier === '' ? '(none)' : $this->plain($dossier),
        ]);
    }

    private function dossier(User $user, ?string $topic): string
    {
        if ($topic === null || $topic === '') {
            return '';
        }

        return $this->plain((string) CommsTopicSearch::query()
            ->whereBelongsTo($user)
            ->where('topic_subject', $topic)
            ->orderBy('id')
            ->value('document'));
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
