<?php

namespace App\Comms\Linking;

/**
 * Decides where a segment goes from the linker runs (agent-definitions 1.3
 * and 3.2). Code, not a model: agents propose, stages decide.
 *
 *  - All runs agree               → that target, certain.
 *  - Two of three agree           → the majority target, uncertain.
 *  - No majority                  → the existing candidate any run chose that
 *                                   ranks highest in the retriever's list,
 *                                   else a new topic; uncertain.
 *
 * An invalid run is a disagreeing run. `ambiguous(a, b)` is already a vote
 * for `a` (LinkVote::fromOutput) and `b` becomes the alternative. With no
 * valid run at all the segment still gets placed, as a new topic.
 */
final class LinkDecision
{
    /**
     * @param  list<LinkVote>  $votes  One per run, in run order.
     * @param  list<string>  $ranking  Candidate keys in the retriever's order, best first
     *                                 (`intent:{id}` and `topic:{subject}`).
     */
    public function decide(array $votes, array $ranking): LinkOutcome
    {
        $runEventIds = array_values(array_filter(array_map(fn (LinkVote $vote): ?int => $vote->decisionEventId, $votes)));
        $valid = array_values(array_filter($votes, fn (LinkVote $vote): bool => $vote->valid && $vote->target !== null));
        $counts = array_count_values(array_map(fn (LinkVote $vote): string => (string) $vote->target, $valid));
        arsort($counts);
        $top = array_key_first($counts);

        if ($top !== null && $counts[$top] === count($votes) && count($votes) > 0) {
            return $this->outcome($top, $valid, $ranking, LinkCertainty::Certain, '3of3', $runEventIds);
        }

        if ($top !== null && $counts[$top] >= 2) {
            return $this->outcome($top, $valid, $ranking, LinkCertainty::Uncertain, '2of3', $runEventIds);
        }

        $existing = array_values(array_filter(array_keys($counts), fn (string $target): bool => $this->isExisting($target)));

        if ($existing !== []) {
            usort($existing, fn (string $left, string $right): int => $this->rank($left, $ranking) <=> $this->rank($right, $ranking));

            return $this->outcome($existing[0], $valid, $ranking, LinkCertainty::Uncertain, 'none', $runEventIds);
        }

        return $this->outcome('new_topic', $valid, $ranking, LinkCertainty::Uncertain, 'none', $runEventIds);
    }

    /**
     * @param  list<LinkVote>  $valid
     * @param  list<string>  $ranking
     * @param  '3of3'|'2of3'|'none'  $agreement
     * @param  list<int>  $runEventIds
     */
    private function outcome(string $target, array $valid, array $ranking, LinkCertainty $certainty, string $agreement, array $runEventIds): LinkOutcome
    {
        $winners = array_values(array_filter($valid, fn (LinkVote $vote): bool => $vote->target === $target));
        $first = $winners[0] ?? null;
        $quote = '';

        foreach ([...$winners, ...$valid] as $vote) {
            if ($vote->quote !== '') {
                $quote = $vote->quote;

                break;
            }
        }

        return new LinkOutcome(
            target: $target,
            topicSubject: $first?->topicSubject ?? (str_starts_with($target, 'topic:') ? substr($target, 6) : null),
            certainty: $certainty,
            agreement: $agreement,
            alternative: $this->alternative($target, $winners, $valid, $ranking),
            quote: $quote,
            newTopicName: $target === 'new_topic' ? ($first?->newTopicName ?? $this->firstNewTopicName($valid)) : null,
            runEventIds: $runEventIds,
        );
    }

    /**
     * The `b` of an ambiguous answer for the winner; else the best-ranked
     * other existing target some run chose.
     *
     * @param  list<LinkVote>  $winners
     * @param  list<LinkVote>  $valid
     * @param  list<string>  $ranking
     */
    private function alternative(string $target, array $winners, array $valid, array $ranking): ?string
    {
        foreach ($winners as $vote) {
            if ($vote->alternative !== null && $vote->alternative !== $target) {
                return $vote->alternative;
            }
        }

        $others = array_values(array_unique(array_filter(
            array_map(fn (LinkVote $vote): string => (string) $vote->target, $valid),
            fn (string $other): bool => $other !== $target && $this->isExisting($other),
        )));
        usort($others, fn (string $left, string $right): int => $this->rank($left, $ranking) <=> $this->rank($right, $ranking));

        return $others[0] ?? null;
    }

    /**
     * @param  list<LinkVote>  $valid
     */
    private function firstNewTopicName(array $valid): ?string
    {
        foreach ($valid as $vote) {
            if ($vote->newTopicName !== null) {
                return $vote->newTopicName;
            }
        }

        return null;
    }

    private function isExisting(string $target): bool
    {
        return str_starts_with($target, 'intent:') || str_starts_with($target, 'topic:');
    }

    /**
     * @param  list<string>  $ranking
     */
    private function rank(string $target, array $ranking): int
    {
        $position = array_search($target, $ranking, true);

        return $position === false ? PHP_INT_MAX : $position;
    }
}
