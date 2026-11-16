<?php

namespace App\Comms\Topics;

use App\Comms\Context\ContextEngine;
use App\Comms\Context\ContextWriter;
use App\Comms\Context\FactOrigin;
use App\Models\Comms\CommsPerson;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The Temas screen. Every change is a user.configured fact operation.
 * Facts are read through the context engine, never from the table.
 */
class TopicReview
{
    public function __construct(private ContextWriter $writer, private ContextEngine $engine) {}

    /**
     * @return list<array{subject: string, name: string, summary: string, state: string, people: list<string>, channels: list<string>, nicknames: list<array{id: int, alias: string}>, proposed: bool}>
     */
    public function list(User $user): array
    {
        $facts = $this->rows($user);
        $people = CommsPerson::query()->whereBelongsTo($user)->get()->keyBy('id');
        $grouped = [];

        foreach ($facts as $fact) {
            if ($fact['kind'] === 'workstream' && str_starts_with($fact['subject'], 'workstream:')) {
                $grouped[$fact['subject']][] = $fact;
            }
        }

        $topics = [];

        foreach ($grouped as $subject => $group) {
            $name = $this->preferred($group, 'name');

            if ($name === null || trim($name['text']) === '') {
                continue;
            }

            $nicknames = [];

            foreach ($this->termsFrom($facts, $subject) as $term) {
                $alias = trim($term['text']);

                if ($alias === '' || str_starts_with($alias, 'workstream:')) {
                    continue;
                }

                $nicknames[] = ['id' => $term['id'], 'alias' => $alias];
            }

            $topics[] = [
                'subject' => (string) $subject,
                'name' => trim($name['text']),
                'summary' => trim((string) ($this->preferred($group, 'summary')['text'] ?? '')),
                'state' => trim((string) ($this->preferred($group, 'state')['text'] ?? '')),
                'people' => $this->labels($this->tokens($this->preferred($group, 'people')), $people),
                'channels' => $this->tokens($this->preferred($group, 'channels')),
                'nicknames' => $nicknames,
                'proposed' => array_any($group, fn (array $fact): bool => $fact['status'] === 'proposed'),
            ];
        }

        usort($topics, fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));

        return $topics;
    }

    public function confirm(User $user, string $subject): bool
    {
        if (! $this->exists($user, $subject)) {
            return false;
        }

        $operations = [];

        foreach ([...$this->workstream($user, $subject), ...$this->terms($user, $subject)] as $fact) {
            if ($fact['status'] === 'proposed') {
                $operations[] = $this->adopt($fact);
            }
        }

        $this->record($user, $operations);

        return true;
    }

    public function rename(User $user, string $subject, string $name): bool
    {
        $name = trim($name);

        if ($name === '' || ! $this->exists($user, $subject)) {
            return false;
        }

        $operations = [];

        foreach ($this->workstream($user, $subject) as $fact) {
            if ($fact['attribute'] === 'name') {
                $operations[] = $this->rejectOp($fact);
            }
        }

        $operations[] = $this->set('workstream', $subject, 'name', ['text' => $name]);
        $this->record($user, $operations);

        return true;
    }

    public function addNickname(User $user, string $subject, string $alias): bool
    {
        $alias = trim($alias);
        $normalized = $this->normalize($alias);

        if ($alias === '' || $normalized === '' || ! $this->exists($user, $subject)) {
            return false;
        }

        foreach ($this->terms($user, $subject) as $term) {
            if ($this->normalize($term['text']) === $normalized) {
                return true;
            }
        }

        $slug = Str::limit(Str::slug($alias), 120, '') ?: 'x';
        $this->record($user, [
            $this->set('term', 'term:'.$slug, 'refers_to', ['text' => $alias, 'value' => $subject]),
        ]);

        return true;
    }

    public function removeNickname(User $user, string $subject, int $factId): bool
    {
        foreach ($this->terms($user, $subject) as $term) {
            if ($term['id'] === $factId) {
                $this->record($user, [$this->rejectOp($term)]);

                return true;
            }
        }

        return false;
    }

    public function merge(User $user, string $from, string $into): bool
    {
        if ($from === $into || ! $this->exists($user, $from) || ! $this->exists($user, $into)) {
            return false;
        }

        $operations = [];
        $taken = [];

        foreach ($this->terms($user, $into) as $term) {
            $taken[$this->normalize($term['text'])] = true;
        }

        $keep = function (string $alias) use (&$operations, &$taken, $into): void {
            $normalized = $this->normalize($alias);

            if ($alias === '' || $normalized === '' || isset($taken[$normalized])) {
                return;
            }

            $taken[$normalized] = true;
            $slug = Str::limit(Str::slug($alias), 120, '') ?: 'x';
            $operations[] = $this->set('term', 'term:'.$slug, 'refers_to', ['text' => $alias, 'value' => $into]);
        };
        $sourceName = $this->preferred($this->workstream($user, $from), 'name');

        if ($sourceName !== null) {
            $keep($sourceName['text']);
        }

        foreach ($this->terms($user, $from) as $term) {
            $keep($term['text']);
            $operations[] = $this->rejectOp($term);
        }

        foreach ($this->workstream($user, $from) as $fact) {
            $operations[] = $this->rejectOp($fact);
        }

        $this->record($user, $operations);

        return true;
    }

    public function reject(User $user, string $subject): bool
    {
        if (! $this->exists($user, $subject)) {
            return false;
        }

        $operations = [];

        foreach ([...$this->workstream($user, $subject), ...$this->terms($user, $subject)] as $fact) {
            $operations[] = $this->rejectOp($fact);
        }

        $this->record($user, $operations);

        return true;
    }

    /**
     * @return list<array{id: int, kind: string, subject: string, attribute: string, text: string, value: array<string, mixed>, status: string, audiences: list<string>, refs: list<string>}>
     */
    private function rows(User $user): array
    {
        $rows = $this->engine->listing($user, ['active', 'proposed'], ['workstream', 'term'], null, 10_000);
        usort($rows, fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return $rows;
    }

    /**
     * @param  list<array{id: int, kind: string, subject: string, attribute: string, text: string, value: array<string, mixed>, status: string, audiences: list<string>, refs: list<string>}>  $facts
     * @return list<array{id: int, kind: string, subject: string, attribute: string, text: string, value: array<string, mixed>, status: string, audiences: list<string>, refs: list<string>}>
     */
    private function termsFrom(array $facts, string $subject): array
    {
        return array_values(array_filter(
            $facts,
            fn (array $fact): bool => $fact['kind'] === 'term' && $this->refersTo($fact) === $subject,
        ));
    }

    /**
     * @return list<array{id: int, kind: string, subject: string, attribute: string, text: string, value: array<string, mixed>, status: string, audiences: list<string>, refs: list<string>}>
     */
    private function workstream(User $user, string $subject): array
    {
        if (! str_starts_with($subject, 'workstream:')) {
            return [];
        }

        return array_values(array_filter(
            $this->rows($user),
            fn (array $fact): bool => $fact['kind'] === 'workstream' && $fact['subject'] === $subject,
        ));
    }

    /**
     * @return list<array{id: int, kind: string, subject: string, attribute: string, text: string, value: array<string, mixed>, status: string, audiences: list<string>, refs: list<string>}>
     */
    private function terms(User $user, string $subject): array
    {
        return $this->termsFrom($this->rows($user), $subject);
    }

    private function exists(User $user, string $subject): bool
    {
        $name = $this->preferred($this->workstream($user, $subject), 'name');

        return $name !== null && trim($name['text']) !== '';
    }

    /**
     * @param  list<array{id: int, attribute: string, status: string, text: string, value: array<string, mixed>}>  $facts
     * @return array{id: int, attribute: string, status: string, text: string, value: array<string, mixed>}|null
     */
    private function preferred(array $facts, string $attribute): ?array
    {
        $matches = array_values(array_filter($facts, fn (array $fact): bool => $fact['attribute'] === $attribute));

        foreach ($matches as $fact) {
            if ($fact['status'] === 'active') {
                return $fact;
            }
        }

        usort($matches, fn (array $left, array $right): int => $right['id'] <=> $left['id']);

        return $matches[0] ?? null;
    }

    /**
     * @param  array{value: array<string, mixed>}  $fact
     */
    private function refersTo(array $fact): mixed
    {
        return $fact['value']['value'] ?? $fact['value']['refers_to'] ?? null;
    }

    /**
     * @param  array{value: array<string, mixed>}|null  $fact
     * @return list<string>
     */
    private function tokens(?array $fact): array
    {
        $value = $fact['value']['value'] ?? null;

        if (! is_array($value)) {
            return [];
        }

        $tokens = [];

        foreach ($value as $token) {
            if (is_string($token) && trim($token) !== '') {
                $tokens[] = trim($token);
            }
        }

        sort($tokens);

        return $tokens;
    }

    /**
     * @param  list<string>  $tokens
     * @param  Collection<int, CommsPerson>  $people
     * @return list<string>
     */
    private function labels(array $tokens, Collection $people): array
    {
        $names = [];

        foreach ($tokens as $token) {
            if (preg_match('/^person:(\d+)$/', $token, $match) === 1) {
                $person = $people->get((int) $match[1]);
                $name = $person instanceof CommsPerson ? trim((string) $person->display_name) : '';
                $names[] = $name !== '' ? $name : $token;

                continue;
            }

            $names[] = $token;
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * @param  list<array<string, mixed>>  $operations
     */
    private function record(User $user, array $operations): void
    {
        if ($operations === []) {
            return;
        }

        $this->writer->record($user, FactOrigin::User, $operations);
    }

    /**
     * @param  array{kind: string, subject: string, attribute: string, value: array<string, mixed>, audiences?: list<string>, refs?: list<string>}  $fact
     * @return array<string, mixed>
     */
    private function adopt(array $fact): array
    {
        return $this->set(
            $fact['kind'],
            $fact['subject'],
            $fact['attribute'],
            $fact['value'],
            $fact['audiences'] ?? [],
            $fact['refs'] ?? [],
        );
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $audiences
     * @param  list<string>  $refs
     * @return array<string, mixed>
     */
    private function set(string $kind, string $subject, string $attribute, array $value, array $audiences = [], array $refs = []): array
    {
        return [
            'op' => 'set',
            'kind' => $kind,
            'subject' => $subject,
            'attribute' => $attribute,
            'value' => $value,
            'audiences' => $audiences,
            'confidence' => 1,
            'refs' => $refs,
        ];
    }

    /**
     * @param  array{kind: string, subject: string, attribute: string, value: array<string, mixed>}  $fact
     * @return array<string, mixed>
     */
    private function rejectOp(array $fact): array
    {
        return [
            'op' => 'reject',
            'kind' => $fact['kind'],
            'subject' => $fact['subject'],
            'attribute' => $fact['attribute'],
            'value' => $fact['value'],
        ];
    }

    private function normalize(string $alias): string
    {
        $folded = Str::lower(Str::ascii($alias));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $folded));
    }
}
