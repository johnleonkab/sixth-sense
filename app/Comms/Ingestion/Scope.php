<?php

namespace App\Comms\Ingestion;

use App\Comms\Events\Source;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsScope;
use App\Models\User;

/**
 * Which messages are part of the user's work. Stored channel ids add to the
 * default: channels where the user posted in the last 30 days.
 */
class Scope
{
    private const JOINS = ['channel_join', 'channel_leave', 'group_join', 'group_leave'];

    public function allowsSlack(User $user, SlackInbound $message): bool
    {
        if (in_array($message->subtype, self::JOINS, true)) {
            return false;
        }

        if (in_array($message->channelKind, ['dm', 'group'], true)) {
            return true;
        }

        if ($message->author->bot || $message->subtype === 'bot_message') {
            return false;
        }

        if ($message->mine || $message->mentionsUser || $message->userInThread || $message->channelAllowed) {
            return true;
        }

        $stored = CommsScope::query()->whereBelongsTo($user)->first();
        $allowed = $stored->slack_channel_ids ?? [];

        if (in_array($message->channelId, $allowed, true)) {
            return true;
        }

        return CommsMessage::query()
            ->whereBelongsTo($user)
            ->where('source', Source::Slack)
            ->where('channel_ref', $message->channelId)
            ->where('direction', 'out')
            ->where('occurred_at', '>=', now()->subDays(30))
            ->exists();
    }

    public function allowsGmail(GmailInbound $message): bool
    {
        $labels = array_map(strtoupper(...), $message->labels);

        foreach (['DRAFT', 'SPAM', 'TRASH', 'CATEGORY_PROMOTIONS', 'CATEGORY_SOCIAL'] as $blocked) {
            if (in_array($blocked, $labels, true)) {
                return false;
            }
        }

        return in_array('INBOX', $labels, true) || in_array('SENT', $labels, true);
    }
}
