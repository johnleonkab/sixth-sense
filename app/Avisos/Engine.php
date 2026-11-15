<?php

namespace App\Avisos;

use App\Alerts\AlertSource;
use App\Alerts\InboxWatchers;
use App\Connectors\Notion\NotionNotices;
use App\Models\ConnectedAccount;
use App\Models\InboxItem;
use App\Models\User;

/**
 * Polls one account and keeps the avisos that the v1 rules accept.
 */
class Engine
{
    public function __construct(
        private InboxWatchers $watchers,
        private FromWatcher $fromWatcher,
        private NotionNotices $notion,
        private Relevance $relevance,
        private DoubtResolver $doubts,
        private Recorder $recorder,
    ) {}

    /**
     * @return list<InboxItem>
     */
    public function check(ConnectedAccount $account, AlertSource $source = AlertSource::Gmail): array
    {
        if ($source === AlertSource::Notion) {
            $candidates = $this->notion->collect($account);
            $stored = $this->keep($account->user, $candidates);
            $this->notion->commit($account);

            return $stored;
        }

        $watcher = $this->watchers->for($source);
        $candidates = array_map(
            fn ($candidate): Candidate => $this->fromWatcher->from($account, $candidate),
            $watcher->newMessages($account),
        );
        $stored = $this->keep($account->user, $candidates);
        $watcher->commit($account);

        return $stored;
    }

    public function score(User $user, Candidate $candidate): ?InboxItem
    {
        $decision = $this->relevance->decide($candidate);

        if ($decision->doubtful && ! $decision->relevant) {
            $decision = $this->doubts->resolve($user, $candidate, $decision);
        }

        return $this->recorder->store($user, $candidate, $decision);
    }

    /**
     * @param  list<Candidate>  $candidates
     * @return list<InboxItem>
     */
    private function keep(User $user, array $candidates): array
    {
        $stored = [];

        foreach ($candidates as $candidate) {
            $item = $this->score($user, $candidate);

            if ($item !== null) {
                $stored[] = $item;
            }
        }

        return $stored;
    }
}
