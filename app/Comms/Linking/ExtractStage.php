<?php

namespace App\Comms\Linking;

use App\Comms\Models\Embeddings;
use App\Comms\Models\ModelAnswerKind;
use App\Comms\Models\ModelRouter;
use App\Comms\People\Relation;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsPreference;
use App\Models\Comms\CommsSegment;
use App\Models\Comms\CommsTopicSearch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Three extractor runs, then the agreement. An invalid run is an empty list.
 */
class ExtractStage
{
    public function __construct(
        private ModelRouter $router,
        private Embeddings $embeddings,
    ) {}

    /**
     * @return list<AgreedOperation>
     */
    public function run(CommsSegment $segment, LinkOutcome $outcome): array
    {
        if ($outcome->isNone()) {
            return [];
        }

        $segment->loadMissing('user');
        $user = $segment->user;
        $messages = CommsMessage::query()
            ->whereBelongsTo($user)
            ->whereIn('id', $segment->message_ids ?? [])
            ->orderBy('id')
            ->get();
        $input = $this->input($user, $segment, $messages, $outcome);
        $eventIds = $messages->pluck('event_id')->map(fn (mixed $id): int => (int) $id)->all();
        $answers = $this->router->runs(
            user: $user,
            task: 'extract',
            input: $input,
            schema: Schemas::extract(),
            eventIds: $eventIds,
            windowId: $segment->window_id,
            inputForRun: fn (int $run): string => $input,
        );
        $runs = [];

        foreach ($answers as $answer) {
            $runs[] = $answer->kind === ModelAnswerKind::Accepted && is_array($answer->output)
                ? $this->operations($answer->output)
                : [];
        }

        return $this->agreement()->compare($runs);
    }

    /**
     * @param  Collection<int, CommsMessage>  $messages
     */
    private function input(User $user, CommsSegment $segment, Collection $messages, LinkOutcome $outcome): string
    {
        $preference = CommsPreference::query()->whereBelongsTo($user)->first();
        $timezone = $preference->timezone ?? (string) config('app.timezone');
        $latest = $messages->max('occurred_at');
        $today = ($latest instanceof CarbonImmutable ? $latest : CarbonImmutable::now())->timezone($timezone)->toDateString();
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
            'timezone: '.$timezone,
            'today: '.$today,
            '',
            'SEGMENT',
            'restated: '.$this->plain((string) $segment->restated),
            implode("\n", $sources),
            '',
            'TOPIC',
            $this->dossier($user, $outcome->topicSubject),
            '',
            'INTENTS',
            $this->intentBlock($user, $messages, $outcome),
            '',
            'ASK PATTERNS',
            '(none)',
            '',
            'PAST CORRECTIONS',
            '(none)',
        ]);
    }

    private function dossier(User $user, ?string $topic): string
    {
        if ($topic === null || $topic === '') {
            return '(none)';
        }

        $document = CommsTopicSearch::query()
            ->whereBelongsTo($user)
            ->where('topic_subject', $topic)
            ->orderBy('id')
            ->value('document');

        $text = $this->plain((string) $document);

        return $text === '' ? '(none)' : $text;
    }

    /**
     * @param  Collection<int, CommsMessage>  $messages
     */
    private function intentBlock(User $user, Collection $messages, LinkOutcome $outcome): string
    {
        $topic = $outcome->topicSubject;
        $intents = $topic === null || $topic === ''
            ? $this->loneIntent($user, $outcome->intentId())
            : $this->topicIntents($user, $topic, $messages);

        if ($intents->isEmpty()) {
            return '(none)';
        }

        return $intents->map(fn (CommsIntent $intent): string => $this->intentLine($intent))->implode("\n");
    }

    /**
     * @return Collection<int, CommsIntent>
     */
    private function loneIntent(User $user, ?int $intentId): Collection
    {
        if ($intentId === null) {
            return new Collection;
        }

        return CommsIntent::query()
            ->whereBelongsTo($user)
            ->whereKey($intentId)
            ->with('participants')
            ->get();
    }

    /**
     * @param  Collection<int, CommsMessage>  $messages
     * @return Collection<int, CommsIntent>
     */
    private function topicIntents(User $user, string $topic, Collection $messages): Collection
    {
        $latest = $messages->max('occurred_at');
        $at = $latest instanceof CarbonImmutable ? $latest : CarbonImmutable::now();
        $since = $at->subDays((int) config('comms.candidates.settled_window_days', 14));

        return CommsIntent::query()
            ->whereBelongsTo($user)
            ->where('topic_subject', $topic)
            ->where(function ($query) use ($since): void {
                $query->where('state', 'open')
                    ->orWhere(function ($settled) use ($since): void {
                        $settled->where('state', 'settled')->where('settled_at', '>=', $since);
                    });
            })
            ->with('participants')
            ->orderBy('id')
            ->get();
    }

    private function intentLine(CommsIntent $intent): string
    {
        $kind = is_array($intent->flags) && is_string($intent->flags['kind'] ?? null) ? $intent->flags['kind'] : '';

        return 'id:'.$intent->id
            .' | what:'.$this->plain((string) $intent->what)
            .' | kind:'.$kind
            .' | requesters:'.$this->people($intent, 'requester')
            .' | responders:'.$this->people($intent, 'responder')
            .' | deadline:'.($intent->deadline?->toDateString() ?? '')
            .' | state:'.$intent->state
            .' | last:'.$this->plain((string) $intent->last_change_summary);
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
     * @param  array<string, mixed>  $output
     * @return list<array<string, mixed>>
     */
    private function operations(array $output): array
    {
        $operations = $output['operations'] ?? null;

        if (! is_array($operations)) {
            return [];
        }

        return array_values(array_filter($operations, fn (mixed $operation): bool => is_array($operation)));
    }

    private function agreement(): ExtractionAgreement
    {
        return new ExtractionAgreement(function (string $left, string $right): float {
            $leftVector = $this->embeddings->vector($left);
            $rightVector = $this->embeddings->vector($right);

            if ($leftVector === null || $rightVector === null) {
                similar_text($left, $right, $percent);

                return $percent / 100;
            }

            return Embeddings::cosine($leftVector, $rightVector);
        }, (float) config('comms.agreement.what_similarity', 0.85));
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
