<?php

namespace App\Alerts\Judges;

use App\Alerts\AlertSource;
use App\Alerts\InboxCandidate;
use App\Alerts\Judgement;
use App\Alerts\RelevanceJudge;
use App\Connectors\Mail\MailAddress;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Decides without AI, for when the AI judge can not answer (no quota, an
 * outage): a message addressed to the person that asks something waits for
 * their answer. Cruder than the AI, but alerts keep arriving.
 */
class RuleRelevanceJudge implements RelevanceJudge
{
    private const ASKS = '/\?|¿|puedes|podrías|podrias|necesito|necesitamos|por favor|urgente|antes del|antes de las|revisa|confirma|me dices|firma|aprueba|can you|could you|asap/iu';

    /** Automatic emails about calendars, tools and digests: never an answer to write. */
    private const AUTOMATIC_SUBJECT = '/^(aceptado|rechazado|tentativo|accepted|declined|tentative|invitaci[oó]n|invitation|updated invitation|invitaci[oó]n actualizada|recordatorio|reminder|notificaci[oó]n|notification)\b|weekly update|daily digest|resumen (diario|semanal)/iu';

    public function judge(User $user, InboxCandidate $candidate): Judgement
    {
        $addressedToThem = match ($candidate->source) {
            AlertSource::Slack => true,
            default => collect($candidate->to)->contains(fn (MailAddress $address): bool => strcasecmp((string) $address->email, $candidate->account) === 0),
        };

        // What is asked is said at the start: the rest is often quoted history or footers.
        $asks = (bool) preg_match(self::ASKS, $candidate->subject.' '.mb_substr($candidate->excerpt, 0, 400));
        $automatic = $candidate->source === AlertSource::Gmail && (bool) preg_match(self::AUTOMATIC_SUBJECT, $candidate->subject);
        $who = $candidate->from?->name ?: $candidate->from?->email ?: 'Alguien';

        return new Judgement(
            important: $addressedToThem && $asks && ! $automatic && ! $candidate->isBulk,
            reason: Str::limit("{$who} te pide algo: «".Str::squish($candidate->source === AlertSource::Gmail ? $candidate->subject : $candidate->excerpt).'»', 150),
            relevance: 50,
        );
    }
}
