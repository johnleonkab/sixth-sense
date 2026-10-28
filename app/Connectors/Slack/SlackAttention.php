<?php

namespace App\Connectors\Slack;

use App\Alerts\Intents\SlackPreference;
use App\Connections\Capability;
use App\Connectors\Chat\ChatMessage;
use App\Models\SlackAttentionProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * Reads the last month of Slack threads the person was in and writes how
 * they actually responded. No model: a reply, someone else answering, a
 * thread that died, or a follow-up poke.
 */
class SlackAttention
{
    private const LOOKBACK_DAYS = 30;

    private const MAX_THREADS = 40;

    public function __construct(
        private SlackApi $slack,
        private SlackChats $chats,
        private SlackPreference $preferences,
    ) {}

    public function learn(User $user): ?SlackAttentionProfile
    {
        if ($user->accountsWith(Capability::SlackSearch)->isEmpty()) {
            return null;
        }

        $previous = $user->slackAttentionProfile;
        $signals = $this->aged($this->signals($this->threads($user)), $previous);
        $body = $this->body($signals);

        if (($signals['stale'] ?? false) === true) {
            $body .= "\nEsto venía del trimestre pasado.";
        }

        $accepted = $user->slackPreferenceRules()->where('status', 'accepted')->exists();

        if ($signals['threads'] < 3 && ! $accepted) {
            $body .= "\n\n¿A quién respondes siempre? ¿Qué proyectos son de este trimestre? ¿Qué canales son ruido?";
        }

        $user->slackAttentionProfile()->updateOrCreate([], [
            'body' => $body,
            'signals' => $signals,
            'built_at' => now(),
        ]);
        $this->preferences->rewrite($user);

        return $user->slackAttentionProfile()->first();
    }

    /**
     * Signals older than a quarter keep half their weight.
     *
     * @param  array{threads: int, replied: int, missed: int, other: int, noise: int, people: list<array{name: string, replied: int}>, channels: list<array{label: string, threads: int, replied: int, missed: int}>, owned_channels: list<string>}  $signals
     * @return array<string, mixed>
     */
    private function aged(array $signals, ?SlackAttentionProfile $previous): array
    {
        $carried = ['replied' => 0, 'missed' => 0, 'other' => 0, 'noise' => 0];
        $earlier = $previous?->signals ?? [];
        $stale = $previous?->built_at !== null && $previous->built_at->lt(now()->subMonths(3));

        if ($stale) {
            foreach ($carried as $key => $value) {
                $carried[$key] = intdiv((int) ($earlier[$key] ?? 0) + (int) ($earlier['aged'][$key] ?? 0), 2);
            }
        } elseif (is_array($earlier['aged'] ?? null)) {
            foreach ($carried as $key => $value) {
                $carried[$key] = (int) ($earlier['aged'][$key] ?? 0);
            }
        }

        $signals['aged'] = $carried;
        $signals['stale'] = $stale;

        return $signals;
    }

    /**
     * How this thread turned out. Null when nothing was asked of the person.
     *
     * @param  list<ChatMessage>  $messages
     * @return 'replied'|'missed'|'other'|'noise'|null
     */
    public static function outcome(array $messages, bool $direct): ?string
    {
        $ordered = $messages;
        usort($ordered, fn (ChatMessage $a, ChatMessage $b): int => $a->sentAt <=> $b->sentAt);

        $ask = null;

        foreach ($ordered as $message) {
            if (! $message->isMine && ($direct || $message->mentionsMe)) {
                $ask = $message;
                break;
            }
        }

        if ($ask === null) {
            return null;
        }

        $reply = null;
        $someoneElse = false;
        $pokedAt = null;

        foreach ($ordered as $message) {
            if ($message->sentAt->lessThanOrEqualTo($ask->sentAt)) {
                continue;
            }

            if ($message->isMine) {
                $reply ??= $message;

                continue;
            }

            $someoneElse = true;

            if ($pokedAt === null && ($message->mentionsMe || self::pokesAgain($message->text))) {
                $pokedAt = $message->sentAt;
            }
        }

        $answeredInADay = $reply !== null && $ask->sentAt->diffInSeconds($reply->sentAt) <= 86400;
        $answeredBeforeThePoke = $reply !== null && ($pokedAt === null || $reply->sentAt->lessThan($pokedAt));

        if ($answeredInADay && $answeredBeforeThePoke) {
            return 'replied';
        }

        if ($pokedAt !== null) {
            return 'missed';
        }

        if ($reply !== null) {
            return 'replied';
        }

        if ($someoneElse) {
            return 'other';
        }

        return 'noise';
    }

    /**
     * @return list<array{outcome: 'replied'|'missed'|'other'|'noise', place: string, where: string, person: ?string}>
     */
    private function threads(User $user): array
    {
        $groups = [];
        $attempts = 0;
        $failures = 0;

        foreach ($user->accountsWith(Capability::SlackSearch) as $account) {
            $me = $this->chats->me($account);
            $after = CarbonImmutable::now()->subDays(self::LOOKBACK_DAYS)->subDay()->toDateString();

            foreach (["to:<@{$me}> after:{$after}", "<@{$me}> after:{$after}", "from:<@{$me}> after:{$after}"] as $query) {
                $attempts++;

                try {
                    $matches = $this->slack->get($account, 'search.messages', [
                        'query' => $query,
                        'sort' => 'timestamp',
                        'sort_dir' => 'desc',
                        'count' => 100,
                    ])['messages']['matches'] ?? [];
                } catch (Throwable $exception) {
                    $failures++;
                    report($exception);

                    continue;
                }

                foreach ($matches as $match) {
                    if (! isset($match['ts'], $match['channel']['id'])) {
                        continue;
                    }

                    $message = $this->chats->fromSearch($account, $match);
                    $direct = (bool) ($match['channel']['is_im'] ?? false) || (bool) ($match['channel']['is_mpim'] ?? false);
                    $key = $direct ? 'dm:'.$message->conversationRef : 'thread:'.($message->threadRef ?? $message->ref);

                    $groups[$key]['direct'] = $direct;
                    $groups[$key]['ref'] = $direct ? $message->conversationRef : ($message->threadRef ?? $message->ref);
                    $groups[$key]['place'] ??= $direct
                        ? ((bool) ($match['channel']['is_im'] ?? false) ? 'dm' : 'group')
                        : ($message->mentionsMe ? 'mention' : 'channel');
                    $groups[$key]['where'] ??= $message->conversationLabel;
                    $groups[$key]['latest'] = max($groups[$key]['latest'] ?? 0, $message->sentAt->getTimestamp());
                    $groups[$key]['messages'][$message->ref] = $message;
                }
            }
        }

        if ($attempts > 0 && $failures === $attempts) {
            throw new RuntimeException('Slack attention history could not be read.');
        }

        uasort($groups, fn (array $a, array $b): int => $b['latest'] <=> $a['latest']);

        $threads = [];

        foreach (array_slice($groups, 0, self::MAX_THREADS) as $group) {
            try {
                $loaded = $group['direct']
                    ? $this->chats->recent($user, $group['ref'], CarbonImmutable::now()->subDays(self::LOOKBACK_DAYS))
                    : $this->chats->thread($user, $group['ref']);
            } catch (Throwable $exception) {
                report($exception);
                $loaded = [];
            }

            $messages = array_values(array_filter(
                $loaded !== [] ? $loaded : $group['messages'],
                fn (ChatMessage $message): bool => $message->authorRef !== null,
            ));
            $outcome = self::outcome($messages, $group['direct']);

            if ($outcome === null) {
                continue;
            }

            $ask = null;

            foreach ($messages as $message) {
                if (! $message->isMine && ($group['direct'] || $message->mentionsMe)) {
                    $ask = $ask === null || $message->sentAt->lessThan($ask->sentAt) ? $message : $ask;
                }
            }

            $threads[] = [
                'outcome' => $outcome,
                'place' => $group['place'],
                'where' => $group['where'],
                'person' => $ask?->authorName,
            ];
        }

        return $threads;
    }

    /**
     * @param  list<array{outcome: 'replied'|'missed'|'other'|'noise', place: string, where: string, person: ?string}>  $threads
     * @return array{threads: int, replied: int, missed: int, other: int, noise: int, people: list<array{name: string, replied: int}>, channels: list<array{label: string, threads: int, replied: int, missed: int}>, owned_channels: list<string>}
     */
    private function signals(array $threads): array
    {
        $counts = ['replied' => 0, 'missed' => 0, 'other' => 0, 'noise' => 0];
        $people = [];
        $channels = [];

        foreach ($threads as $thread) {
            $counts[$thread['outcome']]++;
            $label = $thread['where'];
            $channels[$label] ??= ['label' => $label, 'threads' => 0, 'replied' => 0, 'other' => 0, 'missed' => 0];
            $channels[$label]['threads']++;
            $channels[$label][$thread['outcome']] = ($channels[$label][$thread['outcome']] ?? 0) + 1;

            if ($thread['outcome'] === 'replied' && filled($thread['person'])) {
                $people[$thread['person']] = ($people[$thread['person']] ?? 0) + 1;
            }
        }

        arsort($people);
        uasort($channels, fn (array $a, array $b): int => $b['threads'] <=> $a['threads']);

        $owned = [];

        foreach ($channels as $channel) {
            if (str_starts_with($channel['label'], '#') && $channel['replied'] > 0 && $channel['replied'] > $channel['other']) {
                $owned[] = $channel['label'];
            }
        }

        $peopleList = [];

        foreach ($people as $name => $replied) {
            $peopleList[] = ['name' => $name, 'replied' => $replied];
        }

        return [
            'threads' => count($threads),
            'replied' => $counts['replied'],
            'missed' => $counts['missed'],
            'other' => $counts['other'],
            'noise' => $counts['noise'],
            'people' => $peopleList,
            'channels' => array_values(array_map(fn (array $channel): array => [
                'label' => $channel['label'],
                'threads' => $channel['threads'],
                'replied' => $channel['replied'],
                'missed' => $channel['missed'],
            ], $channels)),
            'owned_channels' => $owned,
        ];
    }

    /**
     * @param  array{threads: int, replied: int, missed: int, other: int, noise: int, people: list<array{name: string, replied: int}>, channels: list<array{label: string, threads: int, replied: int, missed: int}>, owned_channels: list<string>}  $signals
     */
    private function body(array $signals): string
    {
        if ($signals['threads'] === 0) {
            return 'Todavía no hay histórico de Slack suficiente para saber a quién contestas. Cuando lleves unas semanas, o cuando lo digas tú, esto se corrige.';
        }

        $lines = [
            "En el último mes miré {$signals['threads']} hilos. Contestó {$signals['replied']}, se le escaparon {$signals['missed']}, otra persona resolvió {$signals['other']} y {$signals['noise']} no fueron a más.",
        ];

        $names = array_column(array_slice($signals['people'], 0, 3), 'name');

        if ($names !== []) {
            $lines[] = 'A quien más contesta: '.$this->list($names).'.';
        }

        $labels = array_column(array_slice($signals['channels'], 0, 3), 'label');

        if ($labels !== []) {
            $lines[] = 'Donde más aparece: '.$this->list($labels).'.';
        }

        if ($signals['owned_channels'] !== []) {
            $lines[] = 'Canales que parecen suyos: '.$this->list($signals['owned_channels']).'.';
        }

        $missed = array_column(array_filter($signals['channels'], fn (array $channel): bool => $channel['missed'] > 0), 'label');

        if ($missed !== []) {
            $lines[] = 'Se le escapó algo en '.$this->list($missed).'.';
        }

        $lines[] = 'Sale de cómo ha respondido hasta ahora, no de cómo debería responder.';

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $items
     */
    private function list(array $items): string
    {
        if (count($items) < 2) {
            return $items[0] ?? '';
        }

        $last = array_pop($items);

        return implode(', ', $items).' y '.$last;
    }

    public static function pokesAgain(string $text): bool
    {
        return preg_match('/viste esto|lo viste|has visto|me confirmas|sigues con esto/iu', $text) === 1;
    }
}
