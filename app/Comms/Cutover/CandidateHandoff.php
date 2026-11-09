<?php

namespace App\Comms\Cutover;

use App\Alerts\AlertSource;
use App\Alerts\InboxCandidate;
use App\Comms\Ingestion\GmailInbound;
use App\Comms\Ingestion\Ingestor;
use App\Comms\Ingestion\SlackInbound;
use App\Comms\Messages\Party;
use App\Connectors\Mail\MailAddress;
use App\Models\Comms\CommsEvent;
use App\Models\ConnectedAccount;
use Illuminate\Support\Str;

/**
 * A polled message enters the communications log instead of the old inbox.
 */
class CandidateHandoff
{
    public function __construct(private Ingestor $ingestor) {}

    public function accept(ConnectedAccount $account, InboxCandidate $candidate): ?CommsEvent
    {
        $inbound = match ($candidate->source) {
            AlertSource::Slack => $this->slack($account, $candidate),
            AlertSource::Gmail => $this->gmail($account, $candidate),
            default => null,
        };

        if ($inbound === null) {
            return null;
        }

        return $this->ingestor->ingest($account->user, $inbound);
    }

    private function slack(ConnectedAccount $account, InboxCandidate $candidate): SlackInbound
    {
        $pieces = explode(':', $candidate->externalId);
        $ts = array_pop($pieces);
        $channel = array_pop($pieces);
        $kind = in_array($candidate->labels[0] ?? '', ['dm', 'group', 'channel'], true)
            ? $candidate->labels[0]
            : 'channel';
        $thread = null;

        if ($candidate->isReply && is_string($candidate->threadKey) && str_contains($candidate->threadKey, ':')) {
            $thread = Str::afterLast($candidate->threadKey, ':');
        }

        return new SlackInbound(
            teamId: (string) ($account->metadata['team_id'] ?? 'slack'),
            channelId: is_string($channel) && $channel !== '' ? $channel : 'dm',
            ts: is_string($ts) && $ts !== '' ? $ts : $candidate->externalId,
            channelKind: $kind,
            author: $this->party($candidate->from, 'slack'),
            recipients: [],
            text: $candidate->excerpt,
            at: $candidate->receivedAt,
            threadTs: $thread,
            mentionsUser: ($candidate->labels[0] ?? '') === 'mention',
            userInThread: $candidate->isReply,
            channelAllowed: in_array($candidate->labels[0] ?? '', ['channel', 'mention'], true),
        );
    }

    private function gmail(ConnectedAccount $account, InboxCandidate $candidate): GmailInbound
    {
        $messageId = str_contains($candidate->externalId, '.')
            ? Str::after($candidate->externalId, '.')
            : $candidate->externalId;
        $threadId = is_string($candidate->threadKey) && str_contains($candidate->threadKey, '.')
            ? Str::after($candidate->threadKey, '.')
            : $messageId;
        $from = $this->party($candidate->from, 'mail');

        return new GmailInbound(
            messageId: $messageId,
            threadId: $threadId,
            from: $from,
            to: array_map(fn (MailAddress $address): Party => $this->party($address, 'mail'), $candidate->to),
            subject: $candidate->subject,
            body: $candidate->excerpt,
            at: $candidate->receivedAt,
            labels: $candidate->labels === [] ? ['INBOX'] : $candidate->labels,
            mine: $from->email !== null && strcasecmp($from->email, (string) $account->email) === 0,
        );
    }

    private function party(?MailAddress $address, string $fallback): Party
    {
        $email = filled($address?->email) ? strtolower((string) $address->email) : null;
        $name = filled($address?->name) ? (string) $address->name : ($email ?? $fallback);

        return new Party($email ?? $name, $email, $name);
    }
}
