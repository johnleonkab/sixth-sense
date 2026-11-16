<?php

namespace App\Comms\Linking;

use Closure;

/**
 * Compares the intent-extractor runs of one segment (proposal 4 step 6,
 * agent-definitions 1.3). Code, not a model.
 *
 *  - `open` operations are aligned across runs by how similar their `what`
 *    is (embeddings, through the injected similarity). An open every run
 *    returned is Agreed, or UnclearFields when the runs differ on `kind`,
 *    the set of responders, or the deadline date. An open some runs returned
 *    and others did not is a PossibleAsk.
 *  - Every other operation is keyed by `op` and its target intent. Returned
 *    by every run: Agreed. By some: Uncertain.
 *  - `signal` and `noise` agree when every run says so; otherwise Uncertain.
 *
 * An invalid run is passed as an empty list and therefore disagrees.
 */
final class ExtractionAgreement
{
    /**
     * @param  Closure(string, string): float  $similarity  0..1, how alike two `what` sentences are.
     */
    public function __construct(
        private Closure $similarity,
        private float $threshold = 0.85,
    ) {}

    /**
     * @param  list<list<array<string, mixed>>>  $runs  The operations of each run.
     * @return list<AgreedOperation>
     */
    public function compare(array $runs): array
    {
        $total = count($runs);

        if ($total === 0) {
            return [];
        }

        return [...$this->opens($runs, $total), ...$this->others($runs, $total)];
    }

    /**
     * @param  list<list<array<string, mixed>>>  $runs
     * @return list<AgreedOperation>
     */
    private function opens(array $runs, int $total): array
    {
        /** @var list<list<array{run: int, operation: array<string, mixed>}>> $groups */
        $groups = [];

        foreach ($runs as $index => $operations) {
            foreach ($operations as $operation) {
                if (($operation['op'] ?? null) !== 'open') {
                    continue;
                }

                $placed = false;

                foreach ($groups as $key => $group) {
                    $taken = in_array($index, array_column($group, 'run'), true);

                    if (! $taken && ($this->similarity)((string) ($group[0]['operation']['what'] ?? ''), (string) ($operation['what'] ?? '')) >= $this->threshold) {
                        $groups[$key][] = ['run' => $index, 'operation' => $operation];
                        $placed = true;

                        break;
                    }
                }

                if (! $placed) {
                    $groups[] = [['run' => $index, 'operation' => $operation]];
                }
            }
        }

        return array_map(function (array $group) use ($total): AgreedOperation {
            $first = $group[0]['operation'];

            if (count($group) < $total) {
                return new AgreedOperation($first, AgreementStatus::PossibleAsk, runs: count($group));
            }

            $unclear = [];
            $operations = array_column($group, 'operation');

            foreach (['kind' => fn (array $op): string => (string) ($op['kind'] ?? ''),
                'responders' => fn (array $op): string => $this->peopleKey($op['responders'] ?? []),
                'deadline' => fn (array $op): string => (string) ($op['deadline']['date'] ?? $op['deadline'] ?? ''),
            ] as $field => $value) {
                if (count(array_unique(array_map($value, $operations))) > 1) {
                    $unclear[] = $field;
                    $first[$field] = null;
                }
            }

            return new AgreedOperation($first, $unclear === [] ? AgreementStatus::Agreed : AgreementStatus::UnclearFields, $unclear, count($group));
        }, $groups);
    }

    /**
     * @param  list<list<array<string, mixed>>>  $runs
     * @return list<AgreedOperation>
     */
    private function others(array $runs, int $total): array
    {
        /** @var array<string, array{operation: array<string, mixed>, runs: list<int>}> $seen */
        $seen = [];

        foreach ($runs as $index => $operations) {
            foreach ($operations as $operation) {
                $op = (string) ($operation['op'] ?? '');

                if ($op === '' || $op === 'open') {
                    continue;
                }

                $key = $op.'|'.($operation['intent_id'] ?? '');
                $seen[$key] ??= ['operation' => $operation, 'runs' => []];

                if (! in_array($index, $seen[$key]['runs'], true)) {
                    $seen[$key]['runs'][] = $index;
                }
            }
        }

        return array_values(array_map(
            fn (array $entry): AgreedOperation => new AgreedOperation(
                $entry['operation'],
                count($entry['runs']) === $total ? AgreementStatus::Agreed : AgreementStatus::Uncertain,
                runs: count($entry['runs']),
            ),
            $seen,
        ));
    }

    private function peopleKey(mixed $people): string
    {
        $list = array_map('strval', is_array($people) ? $people : []);
        sort($list);

        return implode(',', $list);
    }
}
