<?php

namespace App\Connectors\Notion;

use App\Alerts\AlertSource;
use App\Avisos\Candidate;
use App\Connectors\Wiki\Wiki;
use App\Models\ConnectedAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Comments on pages the person can see, and open tasks that name them.
 * The first check only places a cursor, so older comments never alert.
 */
class NotionNotices
{
    /** @var array<int, string> */
    private array $pendingCursors = [];

    /** @var array<int, list<string>> */
    private array $pendingSeen = [];

    public function __construct(private Wiki $wiki) {}

    /**
     * @return list<Candidate>
     */
    public function collect(ConnectedAccount $account): array
    {
        if ($account->watch_cursor === null) {
            $this->pendingCursors[$account->id] = CarbonImmutable::now()->toIso8601String();

            return [];
        }

        $user = $account->user;
        $since = CarbonImmutable::parse($account->watch_cursor);
        $seen = Cache::get($this->seenKey($account), []);
        $firstName = Str::lower(Str::before($user->name, ' '));
        $candidates = [];
        $handled = [];

        foreach ($this->wiki->search($user, null, 20) as $summary) {
            $page = $this->wiki->page($user, $summary->ref);

            if ($page === null) {
                continue;
            }

            foreach ($this->wiki->comments($user, $summary->ref) as $comment) {
                if ($comment->mine || $comment->resolved || $comment->createdAt->lessThanOrEqualTo($since)) {
                    continue;
                }

                $externalId = $account->id.':'.$summary->ref.':'.$comment->id;

                if (in_array($externalId, $seen, true)) {
                    continue;
                }

                $handled[] = $externalId;
                $candidates[] = new Candidate(
                    source: AlertSource::Notion,
                    externalId: $externalId,
                    actorName: $comment->author,
                    actorEmail: null,
                    title: $summary->title,
                    body: $comment->content,
                    url: $summary->url,
                    receivedAt: $comment->createdAt,
                    threadKey: $summary->ref,
                    ownedPage: true,
                    accountEmail: $account->email,
                );
            }

            if ($firstName === '') {
                continue;
            }

            preg_match_all('/^- \[ \] (.+)$/m', $page->markdown, $tasks);

            foreach ($tasks[1] as $task) {
                if (! str_contains(Str::lower($task), $firstName)) {
                    continue;
                }

                $externalId = $account->id.':'.$summary->ref.':task:'.md5($task);

                if (in_array($externalId, $seen, true)) {
                    continue;
                }

                $handled[] = $externalId;
                $candidates[] = new Candidate(
                    source: AlertSource::Notion,
                    externalId: $externalId,
                    actorName: $user->name,
                    actorEmail: $account->email,
                    title: trim($task),
                    body: trim($task),
                    url: $summary->url,
                    receivedAt: CarbonImmutable::now(),
                    threadKey: $summary->ref,
                    assignedTask: true,
                    accountEmail: $account->email,
                );
            }
        }

        $this->pendingCursors[$account->id] = CarbonImmutable::now()->toIso8601String();
        $this->pendingSeen[$account->id] = array_slice([...$seen, ...$handled], -500);

        return $candidates;
    }

    public function commit(ConnectedAccount $account): void
    {
        if (isset($this->pendingSeen[$account->id])) {
            Cache::put($this->seenKey($account), $this->pendingSeen[$account->id], now()->addDays(30));
            unset($this->pendingSeen[$account->id]);
        }

        if (isset($this->pendingCursors[$account->id])) {
            $account->update(['watch_cursor' => $this->pendingCursors[$account->id], 'watched_at' => now()]);
            unset($this->pendingCursors[$account->id]);
        }
    }

    private function seenKey(ConnectedAccount $account): string
    {
        return "notion-avisos-seen:{$account->id}";
    }
}
