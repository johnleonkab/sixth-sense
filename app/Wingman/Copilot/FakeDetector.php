<?php

namespace App\Wingman\Copilot;

use App\Models\WingmanMeeting;
use Illuminate\Support\Str;

/**
 * For tests and demo mode: findings by simple rules. "Hueco … semana que
 * viene" is a slot; an issue key is an issue; "la página de X" is a page;
 * the person saying "le escribo a X sobre Y" is a message.
 */
class FakeDetector implements Detector
{
    /** @var list<list<array{at: string, speaker: string, is_me: bool, text: string}>> */
    public array $seen = [];

    public function detect(WingmanMeeting $meeting, array $earlier, array $new, array $known): array
    {
        $this->seen[] = $new;
        $findings = [];

        foreach ($new as $line) {
            $text = $line['text'];

            if (preg_match('/\bhueco\b.*\bsemana que viene\b/iu', $text)) {
                $findings[] = new Finding('slot', $text, 0.9, false, [
                    'title' => "Seguimiento: {$meeting->title}",
                    'duration_minutes' => 30,
                    'from_date' => now()->startOfWeek()->addWeek()->toDateString(),
                    'to_date' => now()->startOfWeek()->addWeek()->addDays(4)->toDateString(),
                ]);
            }

            if (preg_match('/\b[A-Z][A-Z0-9]{1,9}-\d+\b/', $text, $key)) {
                $findings[] = new Finding('issue', $text, 0.9, false, ['query' => $key[0]]);
            }

            if (preg_match('/\bla página de ([\p{L} ]+?)(?:[,.?!]|$)/iu', $text, $page)) {
                $findings[] = new Finding('page', $text, 0.8, false, ['query' => trim($page[1])]);
            }

            if ($line['is_me'] && preg_match('/\b[Ll]e (?:escribo|mando un mensaje) a (\p{Lu}[\p{L}]+)(?: sobre| para)? (.+?)[.!]?$/u', $text, $message)) {
                $findings[] = new Finding('message', $text, 0.9, true, [
                    'to' => $message[1],
                    'about' => trim($message[2]),
                    'draft' => 'Hola '.$message[1].', te escribo sobre '.Str::lower(trim($message[2])).'.',
                ]);
            }
        }

        return $findings;
    }
}
