<?php

namespace App\Comms\Topics;

use App\Comms\Context\ContextWriter;
use App\Comms\Context\FactOrigin;
use App\Comms\Events\EventType;
use App\Comms\Linking\Schemas;
use App\Comms\Models\ModelAnswerKind;
use App\Comms\Models\ModelRouter;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Backfill pass B2. Conversations are grouped by channel and ISO week, packed
 * into batches, then merged. Only the merge is stored, as proposed facts.
 */
class TopicBuilder
{
    public function __construct(private ModelRouter $router, private ContextWriter $writer) {}

    /**
     * @return array{topics: int, terms: int}
     */
    public function run(User $user): array
    {
        $messages = CommsMessage::query()
            ->whereBelongsTo($user)
            ->whereNull('deleted_at')
            ->whereNotNull('author_person_id')
            ->orderBy('id')
            ->get()
            ->all();

        usort($messages, function (CommsMessage $left, CommsMessage $right): int {
            return [$this->channel($left), $this->week($left), $left->occurred_at->getTimestamp(), $left->id]
                <=> [$this->channel($right), $this->week($right), $right->occurred_at->getTimestamp(), $right->id];
        });

        if ($messages === []) {
            return ['topics' => 0, 'terms' => 0];
        }

        $known = [];

        foreach ($this->batches($this->groups($messages)) as $batch) {
            [$messageIds, $people, $channels] = $this->allowed($batch, $known);

            foreach ($this->ask($user, $batch, $known) as $topic) {
                if ($this->valid($topic, $messageIds, $people, $channels)) {
                    $known[] = $topic;
                }
            }
        }

        if ($known === []) {
            return ['topics' => 0, 'terms' => 0];
        }

        $counts = ['topics' => 0, 'terms' => 0];
        [$messageIds, $people, $channels] = $this->allowed([], $known);
        $corpus = array_map(fn (CommsMessage $message): int => $message->event_id, $messages);

        foreach ($this->ask($user, [], $known, $corpus) as $topic) {
            if (! $this->valid($topic, $messageIds, $people, $channels)) {
                continue;
            }

            $counts['topics']++;
            $counts['terms'] += $this->write($user, $topic);
        }

        return $counts;
    }

    /**
     * @param  list<CommsMessage>  $messages
     * @return list<array{channel: string, week: string, messages: list<CommsMessage>}>
     */
    private function groups(array $messages): array
    {
        $groups = [];

        foreach ($messages as $message) {
            $channel = $this->channel($message);
            $week = $this->week($message);
            $key = $channel.' '.$week;

            if (! isset($groups[$key])) {
                $groups[$key] = ['channel' => $channel, 'week' => $week, 'messages' => []];
            }

            $groups[$key]['messages'][] = $message;
        }

        return array_values($groups);
    }

    /**
     * @param  list<array{channel: string, week: string, messages: list<CommsMessage>}>  $groups
     * @return list<list<array{channel: string, week: string, messages: list<CommsMessage>}>>
     */
    private function batches(array $groups): array
    {
        $cap = max(1, (int) config('comms.backfill.topic_batch_chars', 60_000));
        $batches = [];
        $current = [];
        $size = 0;

        foreach ($groups as $group) {
            foreach ($this->split($group, $cap) as $piece) {
                $length = mb_strlen($this->block($piece));

                if ($current !== [] && $size + 1 + $length > $cap) {
                    $batches[] = $current;
                    $current = [];
                    $size = 0;
                }

                $current[] = $piece;
                $size += ($size === 0 ? 0 : 1) + $length;
            }
        }

        if ($current !== []) {
            $batches[] = $current;
        }

        return $batches;
    }

    /**
     * @param  array{channel: string, week: string, messages: list<CommsMessage>}  $group
     * @return list<array{channel: string, week: string, messages: list<CommsMessage>}>
     */
    private function split(array $group, int $cap): array
    {
        $pieces = [];
        $current = [];
        $size = mb_strlen($group['channel'].' '.$group['week']);

        foreach ($group['messages'] as $message) {
            $line = mb_strlen($this->source($message));

            if ($current !== [] && $size + 1 + $line > $cap) {
                $pieces[] = ['channel' => $group['channel'], 'week' => $group['week'], 'messages' => $current];
                $current = [];
                $size = mb_strlen($group['channel'].' '.$group['week']);
            }

            $current[] = $message;
            $size += 1 + $line;
        }

        if ($current !== []) {
            $pieces[] = ['channel' => $group['channel'], 'week' => $group['week'], 'messages' => $current];
        }

        return $pieces;
    }

    /**
     * @param  list<array{channel: string, week: string, messages: list<CommsMessage>}>  $pieces
     * @param  list<array{name: string, summary: string, state: string, terms: list<string>, people: list<int>, channels: list<string>, examples: list<int>}>  $known
     * @param  list<int>  $eventIds
     * @return list<array{name: string, summary: string, state: string, terms: list<string>, people: list<int>, channels: list<string>, examples: list<int>}>
     */
    private function ask(User $user, array $pieces, array $known, array $eventIds = []): array
    {
        if ($eventIds === []) {
            foreach ($pieces as $piece) {
                foreach ($piece['messages'] as $message) {
                    $eventIds[] = $message->event_id;
                }
            }
        }

        if ($this->alreadyAsked($user, $eventIds)) {
            return [];
        }

        $answer = $this->router->ask(
            user: $user,
            task: 'topic_build',
            input: $this->input($pieces, $known),
            schema: Schemas::topics(),
            eventIds: $eventIds,
        );

        if ($answer->kind !== ModelAnswerKind::Accepted || ! is_array($answer->output)) {
            return [];
        }

        return $this->parse($answer->output);
    }

    /**
     * @param  list<array{channel: string, week: string, messages: list<CommsMessage>}>  $pieces
     * @param  list<array{name: string, summary: string, state: string, terms: list<string>, people: list<int>, channels: list<string>, examples: list<int>}>  $known
     */
    private function input(array $pieces, array $known): string
    {
        $conversations = array_map(fn (array $piece): string => $this->block($piece), $pieces);
        $topics = array_map(fn (array $topic): string => $this->known($topic), $known);

        return implode("\n", [
            'PEOPLE',
            $this->people($pieces, $known),
            '',
            'CONVERSATIONS',
            $conversations === [] ? '' : implode("\n\n", $conversations),
            '',
            'KNOWN TOPICS',
            $topics === [] ? '' : implode("\n\n", $topics),
        ]);
    }

    /**
     * @param  list<array{channel: string, week: string, messages: list<CommsMessage>}>  $pieces
     * @param  list<array{name: string, summary: string, state: string, terms: list<string>, people: list<int>, channels: list<string>, examples: list<int>}>  $known
     */
    private function people(array $pieces, array $known): string
    {
        $ids = [];

        foreach ($pieces as $piece) {
            foreach ($piece['messages'] as $message) {
                $ids[$message->author_person_id] = true;
            }
        }

        foreach ($known as $topic) {
            foreach ($topic['people'] as $id) {
                $ids[$id] = true;
            }
        }

        if ($ids === []) {
            return '';
        }

        $lines = [];

        foreach (CommsPerson::query()->whereIn('id', array_keys($ids))->orderBy('id')->get() as $person) {
            $line = 'person:'.$person->id.' '.$this->plain($person->display_name);

            if (filled($person->role)) {
                $line .= ' '.$this->plain((string) $person->role);
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array{channel: string, week: string, messages: list<CommsMessage>}  $piece
     */
    private function block(array $piece): string
    {
        $lines = [$piece['channel'].' '.$piece['week']];

        foreach ($piece['messages'] as $message) {
            $lines[] = $this->source($message);
        }

        return implode("\n", $lines);
    }

    private function source(CommsMessage $message): string
    {
        return '<source id="m'.$message->id.'"'
            .' author="person:'.$message->author_person_id.'"'
            .' at="'.$message->occurred_at->toIso8601String().'">'
            .$this->escape((string) $message->text)
            .'</source>';
    }

    /**
     * @param  array{name: string, summary: string, state: string, terms: list<string>, people: list<int>, channels: list<string>, examples: list<int>}  $topic
     */
    private function known(array $topic): string
    {
        $people = array_map(fn (int $id): string => 'person:'.$id, $topic['people']);
        $examples = array_map(fn (int $id): string => 'm'.$id, $topic['examples']);

        return implode("\n", [
            'name: '.$topic['name'],
            'summary: '.$topic['summary'],
            'nicknames: '.implode(', ', $topic['terms']),
            'people: '.implode(', ', $people),
            'channels: '.implode(', ', $topic['channels']),
            'state: '.$topic['state'],
            'examples: '.implode(', ', $examples),
        ]);
    }

    /**
     * @param  array<string, mixed>  $output
     * @return list<array{name: string, summary: string, state: string, terms: list<string>, people: list<int>, channels: list<string>, examples: list<int>}>
     */
    private function parse(array $output): array
    {
        if (! is_array($output['topics'] ?? null)) {
            return [];
        }

        $topics = [];

        foreach ($output['topics'] as $topic) {
            if (! is_array($topic)) {
                continue;
            }

            $name = is_string($topic['name'] ?? null) ? trim($topic['name']) : null;
            $summary = $topic['summary'] ?? null;
            $state = $topic['state'] ?? null;
            $terms = $this->strings($topic['terms'] ?? null);
            $people = $this->personIds($topic['people'] ?? null);
            $channels = $this->strings($topic['channels'] ?? null);
            $examples = $this->messageIds($topic['example_message_ids'] ?? null);

            if ($name === null || $name === '' || ! is_string($summary) || ! is_string($state) || $terms === null || $people === null || $channels === null || $examples === null) {
                continue;
            }

            $topics[] = [
                'name' => $name,
                'summary' => trim($summary),
                'state' => trim($state),
                'terms' => $terms,
                'people' => $people,
                'channels' => $channels,
                'examples' => $examples,
            ];
        }

        return $topics;
    }

    /**
     * @param  list<array{channel: string, week: string, messages: list<CommsMessage>}>  $pieces
     * @param  list<array{name: string, summary: string, state: string, terms: list<string>, people: list<int>, channels: list<string>, examples: list<int>}>  $known
     * @return array{0: array<int, true>, 1: array<int, true>, 2: array<string, true>}
     */
    private function allowed(array $pieces, array $known): array
    {
        $messages = [];
        $people = [];
        $channels = [];

        foreach ($pieces as $piece) {
            $channels[$piece['channel']] = true;

            foreach ($piece['messages'] as $message) {
                $messages[$message->id] = true;
                $people[$message->author_person_id] = true;
            }
        }

        foreach ($known as $topic) {
            foreach ($topic['examples'] as $id) {
                $messages[$id] = true;
            }

            foreach ($topic['people'] as $id) {
                $people[$id] = true;
            }

            foreach ($topic['channels'] as $channel) {
                $channels[$channel] = true;
            }
        }

        return [$messages, $people, $channels];
    }

    /**
     * @param  array{name: string, summary: string, state: string, terms: list<string>, people: list<int>, channels: list<string>, examples: list<int>}  $topic
     * @param  array<int, true>  $messages
     * @param  array<int, true>  $people
     * @param  array<string, true>  $channels
     */
    private function valid(array $topic, array $messages, array $people, array $channels): bool
    {
        foreach ($topic['examples'] as $id) {
            if (! isset($messages[$id])) {
                return false;
            }
        }

        foreach ($topic['people'] as $id) {
            if (! isset($people[$id])) {
                return false;
            }
        }

        foreach ($topic['channels'] as $channel) {
            if (! isset($channels[$channel])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{name: string, summary: string, state: string, terms: list<string>, people: list<int>, channels: list<string>, examples: list<int>}  $topic
     */
    private function write(User $user, array $topic): int
    {
        $slug = Str::limit(Str::slug($topic['name']), 120, '') ?: 'x';
        $subject = 'workstream:'.$slug;
        $people = array_map(fn (int $id): string => 'person:'.$id, $topic['people']);
        sort($people);
        $channels = $topic['channels'];
        sort($channels);
        $exampleIds = $topic['examples'];
        sort($exampleIds);
        $refs = array_map(fn (int $id): string => 'm'.$id, $exampleIds);
        $fact = function (string $kind, string $factSubject, string $attribute, array $value) use ($refs): array {
            return [
                'op' => 'set',
                'kind' => $kind,
                'subject' => $factSubject,
                'attribute' => $attribute,
                'value' => $value,
                'audiences' => [],
                'confidence' => 0.6,
                'refs' => $refs,
                'status' => 'proposed',
            ];
        };
        $operations = [
            $fact('workstream', $subject, 'name', ['text' => $topic['name']]),
            $fact('workstream', $subject, 'summary', ['text' => $topic['summary']]),
            $fact('workstream', $subject, 'state', ['text' => $topic['state']]),
            $fact('workstream', $subject, 'people', ['value' => $people]),
            $fact('workstream', $subject, 'channels', ['value' => $channels]),
        ];
        $seen = [];
        $terms = 0;

        foreach ($topic['terms'] as $term) {
            $termSlug = Str::limit(Str::slug($term), 120, '') ?: 'x';

            if (isset($seen[$termSlug])) {
                continue;
            }

            $seen[$termSlug] = true;
            $operations[] = $fact('term', 'term:'.$termSlug, 'refers_to', [
                'text' => $term,
                'value' => $subject,
            ]);
            $terms++;
        }

        $this->writer->record(
            $user,
            FactOrigin::Inferred,
            $operations,
            revision: 'topic:'.hash('sha1', (string) json_encode($operations, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
        );

        return $terms;
    }

    /**
     * A failed reading is stored as a question. The same messages do not open another one.
     *
     * @param  list<int>  $eventIds
     */
    private function alreadyAsked(User $user, array $eventIds): bool
    {
        $expected = array_values($eventIds);
        sort($expected);

        return CommsEvent::query()
            ->whereBelongsTo($user)
            ->where('type', EventType::Question)
            ->where('normalized->task', 'topic_build')
            ->get()
            ->contains(function (CommsEvent $event) use ($expected): bool {
                $stored = array_values(is_array($event->normalized['event_ids'] ?? null) ? $event->normalized['event_ids'] : []);
                sort($stored);

                return $stored == $expected;
            });
    }

    private function channel(CommsMessage $message): string
    {
        $ref = filled($message->channel_ref)
            ? $message->channel_ref
            : (filled($message->thread_ref) ? 'thread:'.$message->thread_ref : 'none');

        return $message->source->value.':'.$ref;
    }

    private function week(CommsMessage $message): string
    {
        return $message->occurred_at->isoWeekYear().'-W'.str_pad((string) $message->occurred_at->isoWeek(), 2, '0', STR_PAD_LEFT);
    }

    /**
     * @return list<string>|null
     */
    private function strings(mixed $tokens): ?array
    {
        if (! is_array($tokens)) {
            return null;
        }

        $values = [];

        foreach ($tokens as $token) {
            if (! is_string($token)) {
                return null;
            }

            $token = trim($token);

            if ($token !== '') {
                $values[] = $token;
            }
        }

        return $values;
    }

    /**
     * @return list<int>|null
     */
    private function personIds(mixed $tokens): ?array
    {
        if (! is_array($tokens)) {
            return null;
        }

        $ids = [];

        foreach ($tokens as $token) {
            if (! is_string($token) || preg_match('/^person:(\d+)$/', $token, $match) !== 1) {
                return null;
            }

            $ids[] = (int) $match[1];
        }

        return $ids;
    }

    /**
     * @return list<int>|null
     */
    private function messageIds(mixed $tokens): ?array
    {
        if (! is_array($tokens)) {
            return null;
        }

        $ids = [];

        foreach ($tokens as $token) {
            if (! is_string($token) || preg_match('/^m(\d+)$/', $token, $match) !== 1) {
                return null;
            }

            $ids[] = (int) $match[1];
        }

        return $ids;
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
