<?php

namespace App\Alerts;

use App\Models\User;

/**
 * Cheap rules that leave out what is never worth an alert, before asking the
 * relevance judge. Returns why something is noise, or null to judge it.
 */
class NoiseFilter
{
    /** Never listed: the person's own mail, spam, the bin, what is not in the inbox. */
    private const HIDDEN = ['enviado por el usuario', 'spam', 'papelera', 'fuera de la bandeja de entrada'];

    /**
     * Whether something that is noise should not even be listed.
     */
    public function hides(string $reason): bool
    {
        return in_array($reason, self::HIDDEN, true);
    }

    private const IGNORED_LABELS = [
        'CATEGORY_PROMOTIONS' => 'promoción',
        'CATEGORY_SOCIAL' => 'red social',
        'CATEGORY_FORUMS' => 'foro o lista',
        'SPAM' => 'spam',
        'TRASH' => 'papelera',
        'SENT' => 'enviado por el usuario',
    ];

    private const AUTOMATED_SENDER = '/^(no-?reply|do-?not-?reply|mailer-daemon|postmaster|bounce[s]?)([+.\-_].*)?@/i';

    public function noise(User $user, InboxCandidate $candidate): ?string
    {
        // Slack's watcher only brings messages to the user from people.
        if ($candidate->source !== AlertSource::Gmail) {
            return null;
        }

        foreach (self::IGNORED_LABELS as $label => $reason) {
            if (in_array($label, $candidate->labels, true)) {
                return $reason;
            }
        }

        if (! in_array('INBOX', $candidate->labels, true)) {
            return 'fuera de la bandeja de entrada';
        }

        $sender = strtolower((string) $candidate->from?->email);

        if ($sender !== '' && $user->connectedAccounts()->whereRaw('lower(email) = ?', [$sender])->exists()) {
            return 'enviado por el usuario';
        }

        if (preg_match(self::AUTOMATED_SENDER, $sender)) {
            return 'remitente automático';
        }

        if ($candidate->isBulk) {
            return 'envío masivo o newsletter';
        }

        return null;
    }
}
