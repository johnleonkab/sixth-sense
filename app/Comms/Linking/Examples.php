<?php

namespace App\Comms\Linking;

use App\Models\Comms\CommsGoldenLabel;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsSegment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Up to comms.examples.max corrections that share a segment's people or topic.
 */
class Examples
{
    /**
     * @param  Collection<int, CommsMessage>  $messages
     */
    public function block(User $user, CommsSegment $segment, Collection $messages, ?string $topic): string
    {
        $people = $this->people($segment, $messages);
        $max = max(0, (int) config('comms.examples.max', 5));

        if ($max === 0) {
            return '(none)';
        }

        $lines = [];

        foreach ($this->corrections($user) as $correction) {
            $sharesPeople = array_intersect($people, $this->tokens($correction->people)) !== [];
            $sharesTopic = is_string($topic) && $topic !== '' && in_array($topic, $this->tokens($correction->topics), true);

            if (! $sharesPeople && ! $sharesTopic) {
                continue;
            }

            $text = $this->text($correction);

            if ($text === '') {
                continue;
            }

            $lines[] = $correction->label.': '.$text;

            if (count($lines) === $max) {
                break;
            }
        }

        return $lines === [] ? '(none)' : implode("\n", $lines);
    }

    /**
     * @return Collection<int, CommsGoldenLabel>
     */
    private function corrections(User $user): Collection
    {
        return CommsGoldenLabel::query()
            ->whereBelongsTo($user)
            ->where('kind', 'correction')
            ->orderByDesc('id')
            ->limit(200)
            ->get();
    }

    /**
     * @param  Collection<int, CommsMessage>  $messages
     * @return list<string>
     */
    private function people(CommsSegment $segment, Collection $messages): array
    {
        $ids = [];

        foreach ($segment->participants ?? [] as $token) {
            if (is_string($token) && preg_match('/^person:\d+$/', $token) === 1) {
                $ids[$token] = true;
            }
        }

        foreach ($messages as $message) {
            if ($message->author_person_id !== null) {
                $ids['person:'.$message->author_person_id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @return list<string>
     */
    private function tokens(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $tokens = [];

        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $tokens[] = $value;
            }
        }

        return $tokens;
    }

    private function text(CommsGoldenLabel $correction): string
    {
        $expected = $correction->expected;
        $text = is_array($expected) ? ($expected['text'] ?? null) : null;

        return is_string($text) ? trim(str_replace(["\r", "\n"], ' ', $text)) : '';
    }
}
