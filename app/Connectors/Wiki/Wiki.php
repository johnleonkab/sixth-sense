<?php

namespace App\Connectors\Wiki;

use App\Connections\Capability;
use App\Connectors\Docs\DocumentComment;
use App\Connectors\Docs\EditNotPossible;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Knowledge pages (Notion today) across every account of the user.
 */
interface Wiki
{
    /**
     * Pages whose title matches, the most recently edited first; with no
     * query, the latest edited.
     *
     * @return list<WikiPageSummary>
     */
    public function search(User $user, ?string $query, int $limit = 10): array;

    /**
     * Every page the person shared, edited since that moment, the most
     * recently edited first. Without a moment, all of them. To keep a local
     * copy, not to answer a question.
     *
     * @return list<WikiPageSummary>
     */
    public function edited(User $user, ?CarbonImmutable $since, int $limit = 500): array;

    /**
     * Pages from the top of what the person can see, then fewer at each
     * deeper level. The first number is how many roots to keep.
     *
     * @param  list<int>  $widths
     * @return list<array{page: WikiPageSummary, depth: int}>
     */
    public function outline(User $user, array $widths): array;

    /**
     * A page by its ref, its URL or its id. For a URL or id, every account is
     * tried until one can open it.
     */
    public function page(User $user, string $reference): ?WikiPage;

    /**
     * The page's open comment threads, oldest first: on the page and on its blocks.
     *
     * @return list<DocumentComment>
     */
    public function comments(User $user, string $ref): array;

    /**
     * Whether the account behind this page may use the capability.
     */
    public function allows(User $user, string $ref, Capability $capability): bool;

    /**
     * Create a page inside another, with markdown content.
     */
    public function create(User $user, string $parentRef, string $title, string $markdown): WikiPageSummary;

    /**
     * Apply confirmed edits, anchored to text of the page's markdown.
     *
     * @param  list<WikiEdit>  $edits
     *
     * @throws EditNotPossible
     */
    public function edit(User $user, string $ref, array $edits): void;

    /**
     * Comment on the page as the user, or reply in an existing thread.
     * Returns the comment's id.
     */
    public function comment(User $user, string $ref, string $content, ?string $threadId = null): string;
}
