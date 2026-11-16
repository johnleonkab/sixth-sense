<?php

namespace App\Comms\Ingestion;

use App\Comms\Messages\Party;
use App\Connections\Provider;
use App\Connectors\Chat\ChatHistory;
use App\Connectors\Chat\ChatMessage;
use App\Connectors\Mail\Mailbox;
use App\Connectors\Mail\MailMessage;
use App\Connectors\Mail\MailSearch;
use App\Models\ConnectedAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Messages older than the backfill window, read from the connected sources.
 */
class OlderMessages
{
    public function __construct(
        private ChatHistory $chats,
        private Mailbox $mailbox,
    ) {}

    /**
     * @return list<SlackInbound|GmailInbound>
     */
    public function search(User $user, string $term, CarbonImmutable $before): array
    {
        $hits = [];

        if ($this->connected($user, Provider::Slack)) {
            foreach ($this->chats->before($user, $term, $before) as $message) {
                $hits[] = $this->slackHit($message);
            }
        }

        if ($this->connected($user, Provider::Google)) {
            foreach ($this->mailbox->search($user, new MailSearch(text: $term, before: $before, limit: 15))->messages as $found) {
                if ($found->message->date->lessThan($before)) {
                    $hits[] = $this->gmailHit($found->message);
                }
            }
        }

        return $hits;
    }

    private function slackHit(ChatMessage $message): SlackInbound
    {
        $team = str_contains($message->conversationRef, ':') ? Str::before($message->conversationRef, ':') : 'slack';
        $channel = str_contains($message->conversationRef, ':') ? Str::after($message->conversationRef, ':') : $message->conversationRef;
        $ts = str_contains($message->ref, ':') ? Str::afterLast($message->ref, ':') : $message->ref;
        $kind = str_starts_with($channel, 'D') ? 'dm' : 'channel';

        return new SlackInbound(
            teamId: $team,
            channelId: $channel,
            ts: $ts,
            channelKind: $kind,
            author: new Party($message->authorRef ?? $message->authorName, null, $message->authorName),
            recipients: [],
            text: $message->text,
            at: $message->sentAt,
            threadTs: $message->threadRef,
            mentionsUser: $message->mentionsMe,
            channelAllowed: $kind !== 'dm',
            mine: $message->isMine,
        );
    }

    private function gmailHit(MailMessage $message): GmailInbound
    {
        $from = $message->from;
        $to = [];

        foreach ($message->to as $address) {
            $to[] = new Party($address->email ?? $message->ref, $address->email, $address->name ?? ($address->email ?? 'Unknown'));
        }

        return new GmailInbound(
            messageId: $message->ref,
            threadId: $message->threadId ?? $message->ref,
            from: new Party($from?->email ?? $message->ref, $from?->email, $from?->name ?? 'Unknown'),
            to: $to,
            subject: $message->subject,
            body: (string) ($message->body ?? $message->snippet),
            at: $message->date,
            labels: ['INBOX'],
        );
    }

    private function connected(User $user, Provider $provider): bool
    {
        if (config('connectors.demo')) {
            return true;
        }

        return ConnectedAccount::query()->whereBelongsTo($user)->where('provider', $provider)->exists();
    }
}
