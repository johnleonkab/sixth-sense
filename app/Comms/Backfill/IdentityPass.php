<?php

namespace App\Comms\Backfill;

use App\Comms\Events\EventType;
use App\Comms\Intents\PersonRef;
use App\Comms\Linking\Schemas;
use App\Comms\Models\ModelRouter;
use App\Comms\People\Relation;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsPerson;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Backfill pass B1. Exact ids and emails are already one person.
 * This only asks about leftovers, and a shared display name never becomes a proposal.
 */
class IdentityPass
{
    public function __construct(private ModelRouter $router, private PersonRef $people) {}

    /**
     * @return array{candidates: int, proposed: int}
     */
    public function run(User $user): array
    {
        $candidates = $this->candidates($user);

        if ($candidates === []) {
            return ['candidates' => 0, 'proposed' => 0];
        }

        $this->router->ask(
            user: $user,
            task: 'identity_resolve',
            input: $this->input($candidates),
            schema: Schemas::identity(),
        );

        return [
            'candidates' => count($candidates),
            'proposed' => count($this->proposals($user)),
        ];
    }

    /**
     * @return list<array{question: string, evidence: string, names: list<string>, from: string, into: string}>
     */
    public function proposals(User $user): array
    {
        $event = CommsEvent::query()
            ->whereBelongsTo($user)
            ->where('type', EventType::ModelDecided)
            ->where('normalized->task', 'identity_resolve')
            ->orderByDesc('id')
            ->first();
        $pairs = $event->normalized['output']['pairs'] ?? null;

        if (! is_array($pairs)) {
            return [];
        }

        $people = $this->openPeople($user)->keyBy('id');
        $handles = [];

        foreach ($this->candidates($user) as $candidate) {
            $handles[$this->pairKey($candidate['a'], $candidate['b'])] = $candidate['handles'];
        }

        $proposals = [];

        foreach ($pairs as $pair) {
            if (! is_array($pair) || ($pair['verdict'] ?? '') !== 'same' || ! is_string($pair['evidence'] ?? null)) {
                continue;
            }

            $left = $this->id($pair['a'] ?? null);
            $right = $this->id($pair['b'] ?? null);
            $personA = $left === null ? null : $people->get($left);
            $personB = $right === null ? null : $people->get($right);

            if (! $personA instanceof CommsPerson || ! $personB instanceof CommsPerson || $personA->id === $personB->id) {
                continue;
            }

            $key = $this->pairKey($personA, $personB);

            if (($handles[$key] ?? false) !== true) {
                continue;
            }

            $survivor = $personA->id < $personB->id ? $personA : $personB;
            $other = $survivor->id === $personA->id ? $personB : $personA;
            $names = [$personA->display_name, $personB->display_name];
            sort($names);

            $proposals[] = [
                'question' => '¿Son la misma persona?',
                'evidence' => $pair['evidence'],
                'names' => $names,
                'from' => $this->people->key($other),
                'into' => $this->people->key($survivor),
            ];
        }

        return $proposals;
    }

    /**
     * @return list<array{a: CommsPerson, b: CommsPerson, handles: bool}>
     */
    private function candidates(User $user): array
    {
        $people = $this->openPeople($user)->values();
        $candidates = [];

        for ($i = 0; $i < $people->count(); $i++) {
            for ($j = $i + 1; $j < $people->count(); $j++) {
                $left = $people[$i];
                $right = $people[$j];
                $handles = $this->handlesMatch($left, $right);

                if (! $handles && ! $this->namesMatch($left, $right)) {
                    continue;
                }

                $candidates[] = ['a' => $left, 'b' => $right, 'handles' => $handles];
            }
        }

        return $candidates;
    }

    /**
     * @return Collection<int, CommsPerson>
     */
    private function openPeople(User $user): Collection
    {
        return CommsPerson::query()
            ->whereBelongsTo($user)
            ->whereNull('merged_into_id')
            ->where('is_bot', false)
            ->where('relation', '!=', Relation::Self->value)
            ->with('identities')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<array{a: CommsPerson, b: CommsPerson, handles: bool}>  $candidates
     */
    private function input(array $candidates): string
    {
        $lines = ['CANDIDATES'];

        foreach ($candidates as $candidate) {
            $lines[] = 'pair person:'.$candidate['a']->id.' person:'.$candidate['b']->id;
            $lines[] = $this->line($candidate['a']);
            $lines[] = $this->line($candidate['b']);
        }

        return implode("\n", $lines);
    }

    private function line(CommsPerson $person): string
    {
        $identities = $person->identities;
        $handles = $identities->pluck('handle')->filter(fn (mixed $handle): bool => is_string($handle) && $handle !== '')->unique()->implode(' ');
        $emails = $identities->pluck('email')->filter(fn (mixed $email): bool => is_string($email) && $email !== '')->unique()->implode(' ');
        $sources = $identities->map(fn ($identity): string => $identity->source->value)->unique()->implode(' ');

        return 'person:'.$person->id
            .' name="'.$this->plain($person->display_name).'"'
            .' handles="'.$this->plain($handles).'"'
            .' emails="'.$this->plain($emails).'"'
            .' sources="'.$this->plain($sources).'"';
    }

    private function namesMatch(CommsPerson $left, CommsPerson $right): bool
    {
        $slug = Str::slug($left->display_name);

        return $slug !== '' && $slug === Str::slug($right->display_name);
    }

    private function handlesMatch(CommsPerson $left, CommsPerson $right): bool
    {
        foreach ($left->identities as $identity) {
            foreach ($right->identities as $other) {
                if ($this->similarHandle((string) $identity->handle, (string) $other->handle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function similarHandle(string $left, string $right): bool
    {
        $left = $this->handleKey($left);
        $right = $this->handleKey($right);

        if ($left === '' || $right === '') {
            return false;
        }

        if ($left === $right) {
            return true;
        }

        $shorter = strlen($left) <= strlen($right) ? $left : $right;
        $longer = $shorter === $left ? $right : $left;

        if (strlen($shorter) >= 4 && str_contains($longer, $shorter)) {
            return true;
        }

        return strlen($left) >= 4 && strlen($right) >= 4 && levenshtein($left, $right) <= 1;
    }

    private function handleKey(string $handle): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', Str::ascii(Str::lower($handle)));
    }

    private function pairKey(CommsPerson $left, CommsPerson $right): string
    {
        $ids = [$left->id, $right->id];
        sort($ids);

        return $ids[0].':'.$ids[1];
    }

    private function id(mixed $ref): ?int
    {
        if (! is_string($ref) || preg_match('/^person:(\d+)$/', $ref, $match) !== 1) {
            return null;
        }

        return (int) $match[1];
    }

    private function plain(string $value): string
    {
        return trim(str_replace(["\r", "\n", '"'], ' ', $value));
    }
}
