<?php

namespace App\Comms\Intents;

use App\Comms\Events\Source;
use App\Models\Comms\CommsMessage;

/**
 * A link back to the message in the service that sent it.
 */
class MessageUrl
{
    public function for(CommsMessage $message): ?string
    {
        $first = $message->links[0] ?? null;

        if (is_string($first) && $first !== '') {
            return $first;
        }

        if ($message->source === Source::Slack && filled($message->channel_ref) && filled($message->external_id)) {
            $colon = strrpos($message->external_id, ':');
            $stamp = $colon === false ? $message->external_id : substr($message->external_id, $colon + 1);

            return 'https://slack.com/archives/'.$message->channel_ref.'/p'.str_replace('.', '', $stamp);
        }

        if ($message->source === Source::Gmail && filled($message->external_id)) {
            return 'https://mail.google.com/mail/u/0/#all/'.$message->external_id;
        }

        return null;
    }
}
