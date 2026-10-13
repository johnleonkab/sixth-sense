<?php

namespace App\Connectors\Issues;

use App\Connections\Capability;
use App\Connectors\Docs\EditNotPossible;
use App\Connectors\Jira\JiraRules;
use App\Models\User;

/**
 * Issues (Jira today) across every connected site of the user. Writing is
 * only ever called once the person confirmed.
 *
 * Refs: issue "{account}:{KEY}", comment "{account}:{KEY}:{comment id}",
 * link "{account}:{link id}".
 */
interface IssueTracker
{
    /**
     * Issues matching a JQL query, or text in the projects the rules name.
     *
     * @return list<IssueSummary>
     */
    public function search(User $user, ?string $jql, ?string $text = null, int $limit = 15): array;

    /**
     * One issue by its ref, its key or its link.
     */
    public function issue(User $user, string $reference): ?Issue;

    public function allows(User $user, string $ref, Capability $capability): bool;

    /**
     * Each site's rules (how the team works there), with the account they belong to.
     *
     * @return list<array{account_id: int, site: string, rules: JiraRules}>
     */
    public function rules(User $user): array;

    /**
     * People of the site, to mention or assign.
     *
     * @return list<array{id: string, name: string, email: ?string}>
     */
    public function people(User $user, int $accountId, string $query): array;

    /**
     * Priorities the site uses, highest first.
     *
     * @return list<string>
     */
    public function priorities(User $user, int $accountId): array;

    /**
     * Issue types of a project, by name.
     *
     * @return list<array{name: string, subtask: bool}>
     */
    public function issueTypes(User $user, int $accountId, string $projectKey): array;

    /**
     * @throws EditNotPossible
     */
    public function create(User $user, int $accountId, NewIssue $issue): IssueSummary;

    /**
     * @throws EditNotPossible
     */
    public function update(User $user, string $ref, IssueChanges $changes): void;

    /**
     * Mark that one issue blocks another (with the link type of the site's rules).
     *
     * @throws EditNotPossible
     */
    public function link(User $user, string $blockerRef, string $blockedRef): void;

    /**
     * @throws EditNotPossible
     */
    public function unlink(User $user, string $linkRef): void;

    /**
     * Comment as the user; "@Name" in it becomes a mention of the people given.
     *
     * @param  array<string, string>  $mentions  Display name => account id.
     *
     * @throws EditNotPossible
     */
    public function comment(User $user, string $ref, string $markdown, array $mentions = []): string;

    /**
     * Whether the user commented on the issue after this comment.
     */
    public function repliedAfter(User $user, string $commentRef): bool;
}
