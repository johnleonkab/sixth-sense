<?php

namespace App\Avisos;

use App\Alerts\AlertSource;
use Illuminate\Support\Str;

/**
 * v1 relevance. Rules decide. A Gmail message whose only ask sits in quoted
 * text is doubtful and is left to the doubt resolver.
 */
class Relevance
{
    private const ASKS = '/\?|¿|\b(puedes|podrías|podrias|necesito|necesitamos|por favor|urgente|revisa|confirma|firma|aprueba|me dices|can you|could you|please)\b/iu';

    public function decide(Candidate $candidate): Decision
    {
        if ($candidate->fromSelf) {
            return Decision::skip();
        }

        return match ($candidate->source) {
            AlertSource::Slack => $this->slack($candidate),
            AlertSource::Gmail => $this->gmail($candidate),
            AlertSource::Jira => $this->jira($candidate),
            AlertSource::Notion => $this->notion($candidate),
            default => Decision::skip(),
        };
    }

    private function slack(Candidate $candidate): Decision
    {
        $who = $this->who($candidate);

        if ($candidate->directMessage) {
            return Decision::surface("Mensaje directo de {$who}.", 80);
        }

        if ($candidate->mention) {
            return Decision::surface("{$who} te menciona en {$candidate->title}.", 80);
        }

        return Decision::skip();
    }

    private function gmail(Candidate $candidate): Decision
    {
        if (! $candidate->inInbox) {
            return Decision::skip();
        }

        $who = $this->who($candidate);
        $asks = $this->asks($this->unquoted($candidate));

        if ($candidate->importantSender) {
            $why = $asks
                ? "{$who} es un remitente importante y te pide algo."
                : "{$who} es un remitente importante.";

            return Decision::surface($why, 90);
        }

        if ($candidate->bulk) {
            return Decision::skip();
        }

        if ($candidate->replyToOwnThread) {
            $why = $asks
                ? "{$who} responde a un hilo que empezaste y te pide algo."
                : "{$who} responde a un hilo que empezaste.";

            return Decision::surface($why, 75);
        }

        if ($asks) {
            return Decision::surface(Str::limit("{$who} te pide algo: «{$candidate->title}»", 180, '…'), 70);
        }

        if ($this->asks($candidate->title.' '.$candidate->body)) {
            return Decision::skip(doubtful: true);
        }

        return Decision::skip();
    }

    private function jira(Candidate $candidate): Decision
    {
        if (! $candidate->mention) {
            return Decision::skip();
        }

        return Decision::surface("{$this->who($candidate)} te menciona en {$candidate->title}.", 80);
    }

    private function notion(Candidate $candidate): Decision
    {
        if ($candidate->assignedTask) {
            return Decision::surface(Str::limit("Tarea asignada: «{$candidate->title}»", 180, '…'), 85);
        }

        if ($candidate->ownedPage) {
            return Decision::surface(Str::limit("{$this->who($candidate)} comenta en una página tuya: «{$candidate->title}»", 180, '…'), 75);
        }

        return Decision::skip();
    }

    private function asks(string $text): bool
    {
        return (bool) preg_match(self::ASKS, $text);
    }

    /**
     * The person's own words, without lines quoted from an earlier message.
     */
    private function unquoted(Candidate $candidate): string
    {
        $lines = preg_split('/\R/u', $candidate->title."\n".$candidate->body) ?: [];
        $kept = array_filter($lines, fn (string $line): bool => ! preg_match('/^\s*>/', $line));

        return implode("\n", $kept);
    }

    private function who(Candidate $candidate): string
    {
        $name = trim($candidate->actorName);

        return $name !== '' ? $name : 'Alguien';
    }
}
