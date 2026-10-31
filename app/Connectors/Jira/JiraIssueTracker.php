<?php

namespace App\Connectors\Jira;

use App\Connections\Capability;
use App\Connectors\Docs\EditNotPossible;
use App\Connectors\Issues\Issue;
use App\Connectors\Issues\IssueChanges;
use App\Connectors\Issues\IssueComment;
use App\Connectors\Issues\IssueSummary;
use App\Connectors\Issues\IssueTracker;
use App\Connectors\Issues\NewIssue;
use App\Models\ConnectedAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Jira Cloud as the user, across every connected site. Rich text goes both
 * ways through Atlassian Document Format (Adf).
 *
 * @see https://developer.atlassian.com/cloud/jira/platform/rest/v3/
 */
class JiraIssueTracker implements IssueTracker
{
    private const LIST_FIELDS = ['summary', 'issuetype', 'status', 'priority', 'assignee', 'updated'];

    private const ISSUE_FIELDS = [...self::LIST_FIELDS, 'description', 'reporter', 'labels', 'parent', 'subtasks', 'issuelinks'];

    private const KEY = '/\b([A-Z][A-Z0-9_]{1,9}-\d+)\b/';

    public function __construct(private JiraApi $jira) {}

    public function search(User $user, ?string $jql, ?string $text = null, int $limit = 15): array
    {
        $found = [];

        foreach ($user->accountsWith(Capability::JiraRead) as $account) {
            $rules = $this->rulesOf($account);
            $token = null;
            $fromAccount = 0;

            // Jira gives at most 100 per page: more are read page by page.
            do {
                try {
                    $page = $this->jira->post($account, 'search/jql', array_filter([
                        'jql' => $this->query($rules, $jql, $text),
                        'maxResults' => min(100, $limit - $fromAccount),
                        'fields' => self::LIST_FIELDS,
                        'nextPageToken' => $token,
                    ]));
                } catch (JiraError $exception) {
                    report($exception);

                    break;
                }

                foreach ($page['issues'] ?? [] as $issue) {
                    $found[] = $this->summary($account, $issue);
                    $fromAccount++;
                }

                $token = $page['nextPageToken'] ?? null;
            } while (is_string($token) && $fromAccount < $limit);
        }

        usort($found, fn (IssueSummary $a, IssueSummary $b): int => $b->updatedAt <=> $a->updatedAt);

        return array_slice($found, 0, $limit);
    }

    public function involved(User $user, ?CarbonImmutable $since, int $limit = 100): array
    {
        // JQL compares dates by day: the day before is read again, and the
        // caller skips what did not change.
        $from = ($since ?? CarbonImmutable::now()->subYear())->subDay()->format('Y-m-d');
        $jql = "(assignee = currentUser() OR reporter = currentUser() OR watcher = currentUser()) AND updated >= \"{$from}\" ORDER BY updated ASC";
        $found = [];

        foreach ($user->accountsWith(Capability::JiraRead) as $account) {
            $token = null;

            do {
                try {
                    $page = $this->jira->post($account, 'search/jql', array_filter([
                        'jql' => $jql,
                        'maxResults' => min(100, $limit),
                        'fields' => self::LIST_FIELDS,
                        'nextPageToken' => $token,
                    ]));
                } catch (JiraError $exception) {
                    report($exception);

                    break;
                }

                foreach ($page['issues'] ?? [] as $issue) {
                    $found[] = $this->summary($account, $issue);
                }

                $token = $page['nextPageToken'] ?? null;
            } while (is_string($token) && count($found) < $limit);
        }

        usort($found, fn (IssueSummary $a, IssueSummary $b): int => $a->updatedAt <=> $b->updatedAt);

        return array_slice($found, 0, $limit);
    }

    public function issue(User $user, string $reference): ?Issue
    {
        [$account, $key] = $this->resolve($user, $reference, Capability::JiraRead);

        if ($key === null) {
            return null;
        }

        foreach ($account ? [$account] : $user->accountsWith(Capability::JiraRead)->all() as $candidate) {
            try {
                $issue = $this->jira->get($candidate, "issue/{$key}", ['fields' => implode(',', self::ISSUE_FIELDS)]);
                $comments = $this->jira->get($candidate, "issue/{$key}/comment", ['orderBy' => 'created', 'maxResults' => 50])['comments'] ?? [];
            } catch (JiraError) {
                continue;
            }

            return $this->toIssue($candidate, $issue, $comments);
        }

        return null;
    }

    public function allows(User $user, string $ref, Capability $capability): bool
    {
        return $this->resolve($user, $ref, $capability)[0] !== null;
    }

    public function rules(User $user): array
    {
        return $user->accountsWith(Capability::JiraRead)
            ->map(fn (ConnectedAccount $account): array => ['account_id' => $account->id, 'site' => $this->site($account), 'rules' => $this->rulesOf($account)])
            ->values()
            ->all();
    }

    public function people(User $user, int $accountId, string $query): array
    {
        $account = $user->accountsWith(Capability::JiraRead)->firstWhere('id', $accountId);

        if ($account === null || trim($query) === '') {
            return [];
        }

        try {
            $users = $this->jira->get($account, 'user/search', ['query' => $query, 'maxResults' => 10]);
        } catch (JiraError) {
            return [];
        }

        return array_values(array_map(fn (array $person): array => [
            'id' => $person['accountId'],
            'name' => $person['displayName'] ?? $person['accountId'],
            'email' => $person['emailAddress'] ?? null,
        ], array_filter($users, fn (array $person): bool => ($person['accountType'] ?? 'atlassian') === 'atlassian' && ($person['active'] ?? true))));
    }

    public function priorities(User $user, int $accountId): array
    {
        $account = $user->accountsWith(Capability::JiraRead)->firstWhere('id', $accountId);

        try {
            return $account ? array_column($this->jira->get($account, 'priority/search', ['maxResults' => 50])['values'] ?? [], 'name') : [];
        } catch (JiraError) {
            return [];
        }
    }

    public function components(User $user, int $accountId, string $projectKey): array
    {
        $account = $user->accountsWith(Capability::JiraRead)->firstWhere('id', $accountId);

        try {
            return $account ? array_values(array_column($this->jira->get($account, "project/{$projectKey}/components"), 'name')) : [];
        } catch (JiraError) {
            return [];
        }
    }

    public function issueTypes(User $user, int $accountId, string $projectKey): array
    {
        $account = $user->accountsWith(Capability::JiraRead)->firstWhere('id', $accountId);

        try {
            $types = $account ? $this->jira->get($account, "issue/createmeta/{$projectKey}/issuetypes", ['maxResults' => 50]) : [];
        } catch (JiraError) {
            return [];
        }

        return array_map(fn (array $type): array => ['name' => $type['name'], 'subtask' => (bool) ($type['subtask'] ?? false)], $types['issueTypes'] ?? $types['values'] ?? []);
    }

    public function create(User $user, int $accountId, NewIssue $issue): IssueSummary
    {
        $account = $user->accountsWith(Capability::JiraWrite)->firstWhere('id', $accountId)
            ?? throw new EditNotPossible('Esta cuenta de Jira no tiene permiso para crear issues. Actívalo en Configuración.');

        try {
            $created = $this->jira->post($account, 'issue', ['fields' => array_filter([
                'project' => ['key' => $issue->projectKey],
                'issuetype' => ['name' => $issue->type],
                'summary' => $issue->summary,
                'description' => filled($issue->description) ? Adf::fromMarkdown($issue->description) : null,
                'parent' => $issue->parentKey ? ['key' => $issue->parentKey] : null,
                'priority' => $issue->priority ? ['name' => $issue->priority] : null,
                'labels' => $issue->labels ?: null,
                'components' => $issue->components ? array_map(fn (string $name): array => ['name' => $name], $issue->components) : null,
                'assignee' => $issue->assigneeId ? ['id' => $issue->assigneeId] : null,
            ])]);
        } catch (JiraError $exception) {
            throw new EditNotPossible("Jira no ha creado el issue: {$exception->getMessage()}");
        }

        return $this->summary($account, $this->jira->get($account, "issue/{$created['key']}", ['fields' => implode(',', self::LIST_FIELDS)]));
    }

    public function update(User $user, string $ref, IssueChanges $changes): void
    {
        [$account, $key] = $this->accountFor($user, $ref, Capability::JiraWrite);

        try {
            $this->jira->put($account, "issue/{$key}", ['fields' => array_filter([
                'summary' => $changes->summary,
                'description' => $changes->description !== null ? Adf::fromMarkdown($changes->description) : null,
                'priority' => $changes->priority ? ['name' => $changes->priority] : null,
                'labels' => $changes->labels,
                'components' => $changes->components !== null ? array_map(fn (string $name): array => ['name' => $name], $changes->components) : null,
                'assignee' => $changes->assigneeId ? ['id' => $changes->assigneeId] : null,
                'parent' => $changes->parentKey ? ['key' => $changes->parentKey] : null,
            ], fn (mixed $value): bool => $value !== null)]);
        } catch (JiraError $exception) {
            throw new EditNotPossible("Jira no ha aceptado el cambio: {$exception->getMessage()}");
        }
    }

    public function link(User $user, string $blockerRef, string $blockedRef): void
    {
        [$account, $blocker] = $this->accountFor($user, $blockerRef, Capability::JiraWrite);
        [, $blocked] = $this->accountFor($user, $blockedRef, Capability::JiraWrite);

        try {
            // Jira gives the link's outward description ("blocks") to inwardIssue: this reads "blocker blocks blocked".
            $this->jira->post($account, 'issueLink', [
                'type' => ['name' => $this->rulesOf($account)->linkType()],
                'inwardIssue' => ['key' => $blocker],
                'outwardIssue' => ['key' => $blocked],
            ]);
        } catch (JiraError $exception) {
            throw new EditNotPossible("Jira no ha marcado la dependencia: {$exception->getMessage()}");
        }
    }

    public function unlink(User $user, string $linkRef): void
    {
        [$account, $id] = $this->accountFor($user, $linkRef, Capability::JiraWrite);

        try {
            $this->jira->delete($account, "issueLink/{$id}");
        } catch (JiraError $exception) {
            throw new EditNotPossible("Jira no ha quitado la dependencia: {$exception->getMessage()}");
        }
    }

    public function comment(User $user, string $ref, string $markdown, array $mentions = []): string
    {
        [$account, $key] = $this->accountFor($user, $ref, Capability::JiraComment);

        try {
            return (string) $this->jira->post($account, "issue/{$key}/comment", ['body' => Adf::fromMarkdown($markdown, $mentions)])['id'];
        } catch (JiraError $exception) {
            throw new EditNotPossible("Jira no ha aceptado el comentario: {$exception->getMessage()}");
        }
    }

    public function repliedAfter(User $user, string $commentRef): bool
    {
        [$accountId, $key, $commentId] = array_pad(explode(':', $commentRef, 3), 3, null);
        $account = $user->accountsWith(Capability::JiraRead)->firstWhere('id', (int) $accountId);

        if ($account === null || $key === null) {
            return false;
        }

        $comments = $this->jira->get($account, "issue/{$key}/comment", ['orderBy' => 'created', 'maxResults' => 100])['comments'] ?? [];
        $after = collect($comments)->firstWhere('id', $commentId)['created'] ?? null;

        return $after !== null && collect($comments)->contains(fn (array $comment): bool => ($comment['author']['accountId'] ?? null) === $this->me($account)
            && CarbonImmutable::parse($comment['created'])->greaterThan(CarbonImmutable::parse($after)));
    }

    /**
     * Comments that mention the user, made by others since a moment, in the
     * projects the rules name (all projects when they name none). Read only.
     *
     * @return list<array{issue: IssueSummary, comment: IssueComment}>
     */
    public function mentionsSince(ConnectedAccount $account, CarbonImmutable $since, int $limit = 30): array
    {
        $projects = $this->rulesOf($account)->projects();
        $jql = 'updated >= "'.$since->subMinutes(2)->utc()->format('Y-m-d H:i').'"'
            .($projects !== [] ? ' AND project in ('.implode(',', $projects).')' : '')
            .' ORDER BY updated DESC';

        $issues = $this->jira->post($account, 'search/jql', ['jql' => $jql, 'maxResults' => $limit, 'fields' => self::LIST_FIELDS])['issues'] ?? [];
        $found = [];

        foreach ($issues as $issue) {
            $comments = $this->jira->get($account, "issue/{$issue['key']}/comment", ['orderBy' => '-created', 'maxResults' => 20])['comments'] ?? [];

            foreach ($comments as $raw) {
                $comment = $this->toComment($account, $issue['key'], $raw);

                if ($comment->mentionsMe && ! $comment->mine && $comment->createdAt->greaterThan($since)) {
                    $found[] = ['issue' => $this->summary($account, $issue), 'comment' => $comment];
                }
            }
        }

        return $found;
    }

    public function me(ConnectedAccount $account): string
    {
        return (string) ($account->metadata['account_id'] ?? Str::after((string) $account->external_account_id, ':'));
    }

    private function query(JiraRules $rules, ?string $jql, ?string $text): string
    {
        if (filled($jql)) {
            return $jql;
        }

        if (blank($text)) {
            return $rules->baseJql() ?? 'assignee = currentUser() AND statusCategory != Done ORDER BY updated DESC';
        }

        $projects = $rules->projects();

        return 'text ~ "'.str_replace(['\\', '"'], ['\\\\', '\\"'], trim($text)).'"'
            .($projects !== [] ? ' AND project in ('.implode(',', $projects).')' : '')
            .' ORDER BY updated DESC';
    }

    /**
     * @param  array<string, mixed>  $issue
     * @param  list<array<string, mixed>>  $comments
     */
    private function toIssue(ConnectedAccount $account, array $issue, array $comments): Issue
    {
        $fields = $issue['fields'];

        return new Issue(
            summary: $this->summary($account, $issue),
            description: Adf::toBlocks($fields['description'] ?? null),
            descriptionText: Adf::toText($fields['description'] ?? null),
            reporter: $fields['reporter']['displayName'] ?? null,
            labels: $fields['labels'] ?? [],
            parent: isset($fields['parent']) ? [
                'key' => $fields['parent']['key'],
                'summary' => $fields['parent']['fields']['summary'] ?? '',
                'type' => $fields['parent']['fields']['issuetype']['name'] ?? '',
            ] : null,
            subtasks: array_map(fn (array $subtask): array => [
                'key' => $subtask['key'],
                'summary' => $subtask['fields']['summary'] ?? '',
                'status' => $subtask['fields']['status']['name'] ?? '',
            ], $fields['subtasks'] ?? []),
            links: array_values(array_map(function (array $link) use ($account): array {
                $outward = isset($link['outwardIssue']);
                $other = $link['outwardIssue'] ?? $link['inwardIssue'];

                return [
                    'id' => (string) $link['id'],
                    'ref' => "{$account->id}:{$link['id']}",
                    'relation' => $outward ? ($link['type']['outward'] ?? 'relates to') : ($link['type']['inward'] ?? 'relates to'),
                    'key' => $other['key'],
                    'summary' => $other['fields']['summary'] ?? '',
                    'status' => $other['fields']['status']['name'] ?? '',
                ];
            }, $fields['issuelinks'] ?? [])),
            comments: array_map(fn (array $comment): IssueComment => $this->toComment($account, $issue['key'], $comment), $comments),
        );
    }

    /**
     * @param  array<string, mixed>  $comment
     */
    private function toComment(ConnectedAccount $account, string $key, array $comment): IssueComment
    {
        $me = $this->me($account);
        $authorId = $comment['author']['accountId'] ?? null;

        return new IssueComment(
            ref: "{$account->id}:{$key}:{$comment['id']}",
            id: (string) $comment['id'],
            author: $comment['author']['displayName'] ?? 'Alguien',
            authorId: $authorId,
            mine: $authorId === $me,
            text: Adf::toText($comment['body'] ?? null),
            blocks: Adf::toBlocks($comment['body'] ?? null),
            createdAt: CarbonImmutable::parse($comment['created']),
            mentionsMe: in_array($me, Adf::mentions($comment['body'] ?? null), true),
        );
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function summary(ConnectedAccount $account, array $issue): IssueSummary
    {
        $fields = $issue['fields'] ?? [];

        return new IssueSummary(
            ref: "{$account->id}:{$issue['key']}",
            key: $issue['key'],
            summary: (string) ($fields['summary'] ?? ''),
            type: (string) ($fields['issuetype']['name'] ?? ''),
            status: (string) ($fields['status']['name'] ?? ''),
            statusCategory: (string) ($fields['status']['statusCategory']['key'] ?? 'new'),
            priority: $fields['priority']['name'] ?? null,
            assignee: $fields['assignee']['displayName'] ?? null,
            updatedAt: isset($fields['updated']) ? CarbonImmutable::parse($fields['updated']) : null,
            url: rtrim((string) ($account->metadata['site_url'] ?? ''), '/')."/browse/{$issue['key']}",
            site: $this->site($account),
        );
    }

    private function rulesOf(ConnectedAccount $account): JiraRules
    {
        return new JiraRules((string) ($account->rules ?? ''));
    }

    private function site(ConnectedAccount $account): string
    {
        return (string) ($account->metadata['site_name'] ?? $account->email ?? 'Jira');
    }

    /**
     * The account a ref belongs to (if it may use the capability) and the key
     * or id within it; for a key or a link, no account.
     *
     * @return array{?ConnectedAccount, ?string}
     */
    private function resolve(User $user, string $reference, Capability $capability): array
    {
        if (preg_match('/^(\d+):([^:]+)$/', $reference, $match)) {
            return [$user->accountsWith($capability)->firstWhere('id', (int) $match[1]), $match[2]];
        }

        return [null, preg_match(self::KEY, $reference, $match) ? $match[1] : null];
    }

    /**
     * @return array{ConnectedAccount, string}
     *
     * @throws EditNotPossible
     */
    private function accountFor(User $user, string $ref, Capability $capability): array
    {
        [$account, $key] = $this->resolve($user, $ref, $capability);

        if ($account === null || $key === null) {
            throw new EditNotPossible("Esta cuenta de Jira no tiene permiso para «{$capability->label()}». Actívalo en Configuración.");
        }

        return [$account, $key];
    }
}
