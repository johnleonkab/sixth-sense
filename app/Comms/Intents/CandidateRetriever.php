<?php

namespace App\Comms\Intents;

use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Open intents, and intents settled inside the window, that share a thread,
 * a participant, or a ticket key with this message. No model.
 */
class CandidateRetriever
{
    /**
     * @return Collection<int, CommsIntent>
     */
    public function for(User $user, CommsMessage $message): Collection
    {
        $window = (int) config('comms.candidates.settled_window_days', 14);
        $limit = (int) config('comms.candidates.max', 10);
        $keys = $this->ticketKeys($message->text);
        $scored = [];

        $intents = CommsIntent::query()
            ->whereBelongsTo($user)
            ->with(['participants', 'links.message'])
            ->where(function ($query) use ($window, $message): void {
                $query->where('state', 'open')
                    ->orWhere(function ($settled) use ($window, $message): void {
                        $settled->where('state', 'settled')
                            ->where('settled_at', '>=', $message->occurred_at->copy()->subDays($window));
                    });
            })
            ->get();

        foreach ($intents as $intent) {
            $score = $this->score($intent, $message, $keys);

            if ($score > 0) {
                $scored[] = ['intent' => $intent, 'score' => $score];
            }
        }

        usort($scored, fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        return collect($scored)->take($limit)->pluck('intent')->values();
    }

    /**
     * @param  list<string>  $keys
     */
    private function score(CommsIntent $intent, CommsMessage $message, array $keys): int
    {
        $score = 0;
        $linked = $intent->links->map(fn ($link) => $link->message)->filter();

        if (filled($message->thread_ref) && $linked->contains(fn (?CommsMessage $linkedMessage): bool => $linkedMessage?->thread_ref === $message->thread_ref)) {
            $score += 3;
        }

        // Unthreaded Slack DMs use the message ts as thread_ref, so same-channel
        // is how a morning checklist of short asks stays one conversation.
        if (filled($message->channel_ref) && $linked->contains(fn (?CommsMessage $linkedMessage): bool => $linkedMessage?->channel_ref === $message->channel_ref)) {
            $score += 2;
        }

        $participantIds = $intent->participants->pluck('person_id')->all();

        if (in_array($message->author_person_id, $participantIds, true)) {
            $score += 1;
        }

        $haystack = mb_strtolower($intent->what.' '.$linked->pluck('text')->implode(' '));

        foreach ($keys as $key) {
            if (str_contains($haystack, mb_strtolower($key))) {
                $score += 2;
                break;
            }
        }

        return $score;
    }

    /**
     * @return list<string>
     */
    private function ticketKeys(string $text): array
    {
        preg_match_all('/\b[A-Z][A-Z0-9]+-\d+\b/', $text, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }
}
