<?php

namespace App\Wingman\Notes;

use App\Models\WingmanMeeting;
use Illuminate\Support\Str;

/**
 * For tests and demo mode: notes by simple rules. Every line is a point;
 * questions stay open; "vale, entonces…" is a decision; the person saying
 * they will look at, write or send something is a commitment.
 */
class FakeNoteTaker implements NoteTaker
{
    /** @var list<list<array{at: string, speaker: string, is_me: bool, text: string}>> */
    public array $updates = [];

    public function update(WingmanMeeting $meeting, MeetingNotes $notes, array $lines): MeetingNotes
    {
        $this->updates[] = $lines;
        $topics = $notes->topics ?: [['title' => $meeting->title, 'points' => []]];
        $decisions = $notes->decisions;
        $questions = $notes->questions;
        $commitments = $notes->commitments;

        foreach ($lines as $line) {
            $topics[0]['points'][] = "{$line['speaker']}: {$line['text']}";

            match (true) {
                str_ends_with(trim($line['text']), '?') => $questions[] = $line['text'],
                (bool) preg_match('/\b(vale, entonces|decidimos|queda(mos)? en)\b/iu', $line['text']) => $decisions[] = $line['text'],
                $line['is_me'] && (bool) preg_match('/\b(lo miro|te escribo|te mando|le mando|le escribo|preparo)\b/iu', $line['text']) => $commitments[] = ['who' => 'Tú', 'what' => $line['text'], 'when' => null],
                default => null,
            };
        }

        $topics[0]['points'] = array_slice($topics[0]['points'], -8);

        return MeetingNotes::fromArray([
            'summary' => Str::limit(collect($lines)->last()['text'] ?? $notes->summary, 200),
            'topics' => $topics,
            'decisions' => $decisions,
            'questions' => $questions,
            'commitments' => $commitments,
        ]);
    }
}
