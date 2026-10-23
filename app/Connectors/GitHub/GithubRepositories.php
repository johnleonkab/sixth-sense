<?php

namespace App\Connectors\GitHub;

use App\Models\ConnectedAccount;

/**
 * Remembers the repositories a GitHub account can see, keeping the person's
 * choices about which ones to use and which ones may have their code updated.
 */
class GithubRepositories
{
    public function __construct(private GithubApi $api) {}

    public function sync(ConnectedAccount $account): void
    {
        $previous = $account->githubRepositories()->get()->keyBy('github_id');
        $seen = [];

        foreach ($this->api->repositories($account) as $repository) {
            $githubId = (int) $repository['id'];
            $seen[] = $githubId;
            $kept = $previous->get($githubId);
            $canPush = (bool) ($repository['permissions']['push'] ?? false);

            $account->githubRepositories()->updateOrCreate(
                ['github_id' => $githubId],
                [
                    'owner' => $repository['owner']['login'],
                    'name' => $repository['name'],
                    'full_name' => $repository['full_name'],
                    'is_private' => (bool) $repository['private'],
                    'default_branch' => $repository['default_branch'] ?? 'main',
                    'can_push' => $canPush,
                    'included' => (bool) $kept?->included,
                    'writes_code' => (bool) $kept?->included && $canPush && (bool) $kept?->writes_code,
                ],
            );
        }

        $gone = $account->githubRepositories();

        if ($seen === []) {
            $gone->delete();

            return;
        }

        $gone->whereNotIn('github_id', $seen)->delete();
    }
}
