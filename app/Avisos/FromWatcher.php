<?php

namespace App\Avisos;

use App\Alerts\AlertSource;
use App\Alerts\InboxCandidate;
use App\Connections\AccessTokens;
use App\Models\ConnectedAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns a connector watcher candidate into an aviso candidate, including
 * the Gmail signals the rules need (important sender, reply to a thread
 * the person started).
 */
class FromWatcher
{
    private const PROMOTIONS = ['CATEGORY_PROMOTIONS', 'CATEGORY_SOCIAL', 'CATEGORY_UPDATES', 'CATEGORY_FORUMS'];

    public function __construct(private AccessTokens $tokens) {}

    public function from(ConnectedAccount $account, InboxCandidate $candidate): Candidate
    {
        $email = $candidate->from?->email;
        $kind = $candidate->labels[0] ?? null;
        $gmail = $candidate->source === AlertSource::Gmail;
        $actor = $candidate->from?->label() ?? '';

        return new Candidate(
            source: $candidate->source,
            externalId: $candidate->externalId,
            actorName: $actor !== '' ? $actor : 'Alguien',
            actorEmail: $email,
            title: $candidate->subject,
            body: $candidate->excerpt,
            url: $candidate->url,
            receivedAt: $candidate->receivedAt,
            threadKey: $candidate->threadKey,
            directMessage: $kind === 'dm',
            mention: $kind === 'mention' || ($candidate->source === AlertSource::Jira && in_array('mention', $candidate->labels, true)),
            importantSender: $this->important($account, $email),
            replyToOwnThread: $gmail && $candidate->isReply && $this->startedByThem($account, $candidate),
            bulk: $candidate->isBulk || $this->promotional($candidate->labels) || $this->automated($email),
            fromSelf: $email !== null && strcasecmp($email, (string) $account->email) === 0,
            inInbox: ! $gmail || $candidate->labels === [] || in_array('INBOX', $candidate->labels, true),
            accountEmail: $account->email,
        );
    }

    private function important(ConnectedAccount $account, ?string $email): bool
    {
        if ($email === null || $email === '') {
            return false;
        }

        return $account->user->importantSenders()
            ->where('email', Str::lower($email))
            ->exists();
    }

    /**
     * @param  list<string>  $labels
     */
    private function promotional(array $labels): bool
    {
        return array_intersect($labels, self::PROMOTIONS) !== [];
    }

    private function automated(?string $email): bool
    {
        if ($email === null) {
            return false;
        }

        return (bool) preg_match('/^(no-?reply|notifications|mailer-daemon)@/i', $email);
    }

    /**
     * The earliest message of the thread was sent from this account.
     */
    private function startedByThem(ConnectedAccount $account, InboxCandidate $candidate): bool
    {
        if ($candidate->threadKey === null || ! str_starts_with($candidate->threadKey, $account->id.'.')) {
            return false;
        }

        $threadId = Str::after($candidate->threadKey, $account->id.'.');

        try {
            $token = $this->tokens->for($account);
            $response = Http::withToken($token)->acceptJson()->timeout(10)
                ->get('https://gmail.googleapis.com/gmail/v1/users/me/threads/'.rawurlencode($threadId), [
                    'format' => 'metadata',
                    'metadataHeaders' => 'From',
                ]);

            if (! $response->successful()) {
                return false;
            }

            $headers = $response->json('messages.0.payload.headers') ?? [];
            $from = collect($headers)->first(fn (array $header): bool => strcasecmp($header['name'] ?? '', 'from') === 0);

            return is_array($from) && str_contains(Str::lower((string) ($from['value'] ?? '')), Str::lower((string) $account->email));
        } catch (Throwable) {
            return false;
        }
    }
}
