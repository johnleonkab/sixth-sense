<?php

namespace App\Comms\Surface;

use App\Comms\Gate\ProposalStatus;
use App\Comms\People\Relation;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsIntentParticipant;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsPlaybookRun;
use App\Models\Comms\CommsProposal;
use App\Models\Comms\CommsQuestion;
use App\Models\Comms\CommsWatcher;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The home by who has the ball. Counts are loaded once per fact, then
 * BallHolder decides the group. Hidden intents are left out.
 */
class HomeBoard
{
    /**
     * @return array{
     *     counters: array{needs_you: int, system_working: int, waiting_on_others: int, done_today: int},
     *     lists: array{needs_you: list<array<string, mixed>>, system_working: list<array<string, mixed>>, waiting_on_others: list<array<string, mixed>>, done_today: list<array<string, mixed>>},
     *     possible_asks: int,
     *     possible: list<array<string, mixed>>,
     *     uncertain_links: int,
     *     uncertain: list<array{id: int, intent_id: int, what: mixed, alternative: ?string}>,
     *     stale: list<array<string, mixed>>,
     *     topics: list<array{subject: ?string, intent_ids: list<int>}>
     * }
     */
    public static function for(User $user): array
    {
        $now = CarbonImmutable::now();
        $intents = CommsIntent::query()->whereBelongsTo($user)->orderBy('id')->get();
        $empty = [
            'counters' => [
                'needs_you' => 0,
                'system_working' => 0,
                'waiting_on_others' => 0,
                'done_today' => 0,
            ],
            'lists' => [
                'needs_you' => [],
                'system_working' => [],
                'waiting_on_others' => [],
                'done_today' => [],
            ],
            'possible_asks' => 0,
            'possible' => [],
            'uncertain_links' => 0,
            'uncertain' => [],
            'stale' => [],
            'topics' => [],
        ];

        if ($intents->isEmpty()) {
            return $empty;
        }

        $selfId = CommsPerson::query()->whereBelongsTo($user)->where('relation', Relation::Self)->value('id');
        $roles = $selfId === null
            ? new Collection
            : CommsIntentParticipant::query()
                ->whereBelongsTo($user)
                ->where('person_id', $selfId)
                ->whereIn('role', ['requester', 'responder'])
                ->get(['intent_id', 'role']);
        $pending = self::proposalCounts($user, ProposalStatus::Pending);
        $executed = self::proposalCounts($user, ProposalStatus::Executed);
        $questions = self::grouped(
            CommsQuestion::query()->whereBelongsTo($user)->whereNotNull('intent_id')->whereNull('answered_event_id'),
        );
        $runs = self::grouped(
            CommsPlaybookRun::query()->whereBelongsTo($user)->whereNotNull('intent_id')->where('state', '!=', 'done'),
        );
        $watchers = self::grouped(
            CommsWatcher::query()->whereBelongsTo($user)->where('state', 'active'),
        );
        $uncertainLinks = CommsConversationLink::query()
            ->where('comms_conversation_links.user_id', $user->id)
            ->where('uncertain', true)
            ->join('comms_intents', 'comms_intents.id', '=', 'comms_conversation_links.intent_id')
            ->orderBy('comms_conversation_links.id')
            ->get([
                'comms_conversation_links.id',
                'comms_conversation_links.intent_id',
                'comms_conversation_links.alternative',
                'comms_intents.what',
            ]);
        $lists = [
            BallGroup::NeedsYou->value => [],
            BallGroup::SystemWorking->value => [],
            BallGroup::WaitingOnOthers->value => [],
            BallGroup::DoneToday->value => [],
        ];
        $stale = [];
        $possibleRows = [];
        $possible = 0;
        $visible = [];

        foreach ($intents as $intent) {
            $facts = [
                'state' => (string) $intent->state,
                'settled_at' => $intent->settled_at,
                'possible_ask' => (bool) $intent->possible_ask,
                'snoozed_until' => $intent->snoozed_until,
                'user_is_responder' => $roles->contains(fn ($role): bool => $role->intent_id === $intent->id && $role->role === 'responder'),
                'user_is_requester' => $roles->contains(fn ($role): bool => $role->intent_id === $intent->id && $role->role === 'requester'),
                'pending_proposals' => $pending[$intent->id] ?? 0,
                'open_questions' => $questions[$intent->id] ?? 0,
                'executed_actions' => $executed[$intent->id] ?? 0,
                'active_runs' => $runs[$intent->id] ?? 0,
                'waiting_watchers' => $watchers[$intent->id] ?? 0,
            ];
            $group = BallHolder::group($facts, $now);
            $row = [
                'id' => $intent->id,
                'key' => $intent->key,
                'what' => $intent->what,
                'importance' => (int) $intent->importance,
                'deadline' => $intent->deadline?->toDateString(),
                'topic_subject' => $intent->topic_subject,
                'pending_proposals' => $facts['pending_proposals'],
                'open_questions' => $facts['open_questions'],
                'executed_actions' => $facts['executed_actions'],
                'active_runs' => $facts['active_runs'],
                'waiting_watchers' => $facts['waiting_watchers'],
            ];

            if ($group === BallGroup::Hidden) {
                continue;
            }

            $visible[] = $row;

            if ($group === BallGroup::PossibleAsk) {
                $possible++;
                $possibleRows[] = $row;

                continue;
            }

            if ($group === BallGroup::Stale) {
                $stale[] = $row;

                continue;
            }

            $lists[$group->value][] = $row;
        }

        foreach ($lists as $key => $rows) {
            $lists[$key] = self::sort($rows);
        }

        return [
            'counters' => [
                'needs_you' => count($lists[BallGroup::NeedsYou->value]),
                'system_working' => count($lists[BallGroup::SystemWorking->value]),
                'waiting_on_others' => count($lists[BallGroup::WaitingOnOthers->value]),
                'done_today' => count($lists[BallGroup::DoneToday->value]),
            ],
            'lists' => [
                'needs_you' => $lists[BallGroup::NeedsYou->value],
                'system_working' => $lists[BallGroup::SystemWorking->value],
                'waiting_on_others' => $lists[BallGroup::WaitingOnOthers->value],
                'done_today' => $lists[BallGroup::DoneToday->value],
            ],
            'possible_asks' => $possible,
            'possible' => self::sort($possibleRows),
            'uncertain_links' => $uncertainLinks->count(),
            'uncertain' => $uncertainLinks->map(function (CommsConversationLink $link): array {
                $alternative = $link->alternative;
                $first = is_array($alternative) ? ($alternative[0] ?? null) : null;

                return [
                    'id' => $link->id,
                    'intent_id' => $link->intent_id,
                    'what' => $link->getAttribute('what'),
                    'alternative' => is_string($first) ? $first : null,
                ];
            })->all(),
            'stale' => self::sort($stale),
            'topics' => self::topics($visible),
        ];
    }

    /**
     * @return array<int, int>
     */
    private static function proposalCounts(User $user, ProposalStatus $status): array
    {
        $query = CommsProposal::query()->whereBelongsTo($user)->whereNotNull('intent_id')->where('status', $status);

        if ($status === ProposalStatus::Pending) {
            $query->whereNull('payload->delegate');
        }

        return self::grouped($query);
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return array<int, int>
     */
    private static function grouped($query): array
    {
        return $query
            ->selectRaw('intent_id, count(*) as aggregate')
            ->groupBy('intent_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->intent_id => (int) $row->aggregate])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function sort(array $rows): array
    {
        usort($rows, function (array $left, array $right): int {
            return [-$left['importance'], $left['deadline'] ?? '9999-99-99', $left['id']]
                <=> [-$right['importance'], $right['deadline'] ?? '9999-99-99', $right['id']];
        });

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{subject: ?string, intent_ids: list<int>}>
     */
    private static function topics(array $rows): array
    {
        $rows = self::sort($rows);
        $groups = [];

        foreach ($rows as $row) {
            $subject = is_string($row['topic_subject']) && $row['topic_subject'] !== '' ? $row['topic_subject'] : null;
            $groups[$subject ?? ''][] = $row['id'];
        }

        uksort($groups, function (string $left, string $right): int {
            if ($left === '' || $right === '') {
                return $left === $right ? 0 : ($left === '' ? 1 : -1);
            }

            return $left <=> $right;
        });
        $topics = [];

        foreach ($groups as $subject => $ids) {
            $topics[] = [
                'subject' => $subject === '' ? null : $subject,
                'intent_ids' => $ids,
            ];
        }

        return $topics;
    }
}
