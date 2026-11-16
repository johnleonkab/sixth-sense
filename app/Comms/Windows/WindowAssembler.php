<?php

namespace App\Comms\Windows;

use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsWindow;

/**
 * The new messages of a window plus earlier ones from the same conversation,
 * capped by the lookback config, and the people who wrote them.
 */
class WindowAssembler
{
    public function for(CommsWindow $window): AssembledWindow
    {
        $newIds = array_values(array_map(intval(...), $window->message_ids ?? []));
        $hours = (int) config('comms.window.lookback_hours');
        $limit = (int) config('comms.window.lookback_messages');

        $earlier = CommsMessage::query()
            ->where('user_id', $window->user_id)
            ->where('source', $window->source)
            ->where('occurred_at', '>=', $window->opened_at->subHours($hours))
            ->where('occurred_at', '<', $window->opened_at)
            ->whereNull('deleted_at')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get()
            ->filter(function (CommsMessage $message) use ($window, $newIds): bool {
                if (in_array($message->id, $newIds, true)) {
                    return false;
                }

                return ConversationGroup::of($message)->ref === $window->group_ref;
            })
            ->take($limit)
            ->values();

        $fresh = $newIds === []
            ? collect()
            : CommsMessage::query()->where('user_id', $window->user_id)->whereIn('id', $newIds)->get();

        $messages = $earlier
            ->concat($fresh)
            ->unique('id')
            ->sort(fn (CommsMessage $left, CommsMessage $right): int => $left->occurred_at->getTimestamp() <=> $right->occurred_at->getTimestamp()
                ?: $left->id <=> $right->id)
            ->values();

        $messages->load('author');

        $rows = $messages->map(fn (CommsMessage $message): AssembledMessage => new AssembledMessage(
            $message,
            in_array($message->id, $newIds, true),
        ))->all();

        $people = $messages
            ->map(fn (CommsMessage $message): ?CommsPerson => $message->author)
            ->filter()
            ->unique('id')
            ->sortBy('id')
            ->values()
            ->all();

        return new AssembledWindow($window, array_values($rows), array_values($people));
    }
}
