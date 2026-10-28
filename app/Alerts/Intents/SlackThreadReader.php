<?php

namespace App\Alerts\Intents;

use App\Alerts\AlertSource;
use App\Alerts\InboxCandidate;
use App\Connectors\Chat\ChatMessage;
use App\Connectors\Slack\SlackChats;
use App\Models\User;
use Throwable;

/**
 * Reads the Slack thread a message belongs to, once, so triage can see
 * whether she opened it and whether someone already answered.
 */
class SlackThreadReader
{
    public function __construct(private SlackChats $chats) {}

    public function read(User $user, InboxCandidate $candidate): SlackThreadState
    {
        $place = $this->place($candidate);
        $openedBySelf = false;
        $alreadyAnswered = false;
        $answeredBy = null;
        $ref = $candidate->threadKey ?? $candidate->externalId;

        if ($candidate->source === AlertSource::Slack && substr_count($ref, ':') >= 2) {
            try {
                $messages = $this->chats->thread($user, $ref);
                usort($messages, fn (ChatMessage $a, ChatMessage $b): int => $a->sentAt <=> $b->sentAt);
                $openedBySelf = ($messages[0] ?? null)?->isMine === true;
                [$alreadyAnswered, $answeredBy] = $this->answer($messages, $candidate->externalId);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return new SlackThreadState(
            place: $place,
            channel: $this->channel($candidate),
            directed: in_array($place, ['dm', 'group', 'mention'], true),
            openedBySelf: $openedBySelf,
            alreadyAnswered: $alreadyAnswered,
            answeredBy: $answeredBy,
            fromName: $candidate->from?->name,
            ask: $candidate->excerpt,
        );
    }

    private function place(InboxCandidate $candidate): string
    {
        $label = $candidate->labels[0] ?? null;

        return in_array($label, ['dm', 'group', 'mention', 'thread', 'channel'], true) ? $label : 'mention';
    }

    private function channel(InboxCandidate $candidate): ?string
    {
        if (preg_match('/(#[\p{L}\p{N}_-]+)/u', $candidate->subject, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    /**
     * @param  list<ChatMessage>  $messages
     * @return array{0: bool, 1: ?string}
     */
    private function answer(array $messages, string $externalId): array
    {
        $ask = null;

        foreach ($messages as $message) {
            if ($message->ref === $externalId) {
                $ask = $message;

                break;
            }
        }

        foreach ($messages as $message) {
            if ($message->isMine || $message->authorRef === null) {
                continue;
            }

            if ($ask !== null && $message->sentAt->lessThanOrEqualTo($ask->sentAt)) {
                continue;
            }

            if ($message->ref === $externalId) {
                continue;
            }

            if (mb_strlen(trim($message->text)) < 12 || preg_match('/viste esto|lo viste|has visto/iu', $message->text) === 1) {
                continue;
            }

            return [true, $message->authorName];
        }

        return [false, null];
    }
}
