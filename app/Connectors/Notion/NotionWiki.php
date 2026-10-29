<?php

namespace App\Connectors\Notion;

use App\Connections\AccessTokens;
use App\Connections\Capability;
use App\Connectors\Docs\DocumentComment;
use App\Connectors\Docs\EditNotPossible;
use App\Connectors\Wiki\Wiki;
use App\Connectors\Wiki\WikiEdit;
use App\Connectors\Wiki\WikiPage;
use App\Connectors\Wiki\WikiPageSummary;
use App\Models\ConnectedAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Notion pages as the user, across every connected workspace. Content is
 * read and edited as markdown.
 *
 * Refs: page "{account}:{page id}".
 *
 * @see https://developers.notion.com/guides/data-apis/working-with-markdown-content
 */
class NotionWiki implements Wiki
{
    /** Blocks whose inline comments are read: the top of the page, where they are usually. */
    private const COMMENTED_BLOCKS = 25;

    public function __construct(
        private NotionApi $notion,
        private AccessTokens $tokens,
    ) {}

    public function search(User $user, ?string $query, int $limit = 10): array
    {
        $found = [];

        foreach ($user->accountsWith(Capability::NotionRead) as $account) {
            try {
                $results = $this->notion->post($account, 'search', array_filter([
                    'query' => filled($query) ? $query : null,
                    'filter' => ['property' => 'object', 'value' => 'page'],
                    'sort' => ['direction' => 'descending', 'timestamp' => 'last_edited_time'],
                    'page_size' => min(50, $limit * 2),
                ]))['results'] ?? [];
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            foreach ($results as $page) {
                if (! ($page['in_trash'] ?? $page['archived'] ?? false)) {
                    $found[] = $this->summary($account, $page);
                }
            }
        }

        usort($found, fn (WikiPageSummary $a, WikiPageSummary $b): int => $b->editedAt <=> $a->editedAt);

        return array_slice($found, 0, $limit);
    }

    public function edited(User $user, ?CarbonImmutable $since, int $limit = 500): array
    {
        $found = [];

        foreach ($user->accountsWith(Capability::NotionRead) as $account) {
            $cursor = null;

            do {
                try {
                    $results = $this->notion->post($account, 'search', array_filter([
                        'filter' => ['property' => 'object', 'value' => 'page'],
                        'sort' => ['direction' => 'descending', 'timestamp' => 'last_edited_time'],
                        'page_size' => 100,
                        'start_cursor' => $cursor,
                    ]));
                } catch (Throwable $exception) {
                    report($exception);

                    break;
                }

                $older = false;

                foreach ($results['results'] ?? [] as $page) {
                    if ($page['in_trash'] ?? $page['archived'] ?? false) {
                        continue;
                    }

                    $summary = $this->summary($account, $page);

                    if ($since !== null && $summary->editedAt !== null && $summary->editedAt->lessThan($since)) {
                        $older = true;

                        break;
                    }

                    $found[] = $summary;
                }

                $cursor = ($results['has_more'] ?? false) ? ($results['next_cursor'] ?? null) : null;
            } while (! $older && is_string($cursor) && count($found) < $limit);
        }

        usort($found, fn (WikiPageSummary $a, WikiPageSummary $b): int => $b->editedAt <=> $a->editedAt);

        return array_slice($found, 0, $limit);
    }

    public function outline(User $user, array $widths): array
    {
        $widths = array_values(array_filter($widths, fn (int $width): bool => $width > 0));

        if ($widths === []) {
            return [];
        }

        $listed = [];

        foreach ($user->accountsWith(Capability::NotionRead) as $account) {
            try {
                $results = $this->notion->post($account, 'search', [
                    'filter' => ['property' => 'object', 'value' => 'page'],
                    'sort' => ['direction' => 'descending', 'timestamp' => 'last_edited_time'],
                    'page_size' => 50,
                ])['results'] ?? [];
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            foreach ($results as $page) {
                if ($page['in_trash'] ?? $page['archived'] ?? false) {
                    continue;
                }

                $listed[] = ['account' => $account, 'page' => $page, 'summary' => $this->summary($account, $page)];
            }
        }

        $known = [];

        foreach ($listed as $item) {
            $known[(string) $item['page']['id']] = true;
        }

        $roots = array_values(array_filter($listed, function (array $item) use ($known): bool {
            $parent = $item['page']['parent']['type'] ?? 'workspace';

            if ($parent === 'page_id') {
                return ! isset($known[(string) ($item['page']['parent']['page_id'] ?? '')]);
            }

            return true;
        }));

        $outline = [];
        $reads = 0;
        $walk = function (array $items, int $depth) use (&$walk, &$outline, &$reads, $widths): void {
            foreach (array_slice($items, 0, $widths[$depth]) as $item) {
                $outline[] = ['page' => $item['summary'], 'depth' => $depth];

                if (! isset($widths[$depth + 1]) || $reads >= 24) {
                    continue;
                }

                $reads++;

                try {
                    $children = $this->childPages($item['account'], (string) ($item['id'] ?? $item['page']['id']), $item['summary']->title, $widths[$depth + 1]);
                } catch (Throwable $exception) {
                    report($exception);

                    continue;
                }

                $walk($children, $depth + 1);
            }
        };
        $walk($roots, 0);

        return $outline;
    }

    public function page(User $user, string $reference): ?WikiPage
    {
        [$account, $id] = $this->resolve($user, $reference, Capability::NotionRead);

        if ($id === null) {
            return null;
        }

        // A link or an id: whichever account can open it.
        $accounts = $account ? [$account] : $user->accountsWith(Capability::NotionRead)->all();

        foreach ($accounts as $candidate) {
            try {
                $page = $this->notion->get($candidate, "pages/{$id}");
                $markdown = $this->notion->get($candidate, "pages/{$id}/markdown");
            } catch (NotionError) {
                continue;
            }

            return new WikiPage(
                ref: "{$candidate->id}:{$page['id']}",
                title: self::title($page),
                icon: $page['icon']['emoji'] ?? null,
                url: $page['url'] ?? null,
                publicUrl: $page['public_url'] ?? null,
                markdown: (string) ($markdown['markdown'] ?? ''),
                workspace: self::workspace($candidate),
                editedAt: isset($page['last_edited_time']) ? CarbonImmutable::parse($page['last_edited_time']) : null,
                truncated: (bool) ($markdown['truncated'] ?? false),
            );
        }

        return null;
    }

    public function comments(User $user, string $ref): array
    {
        [$account, $id] = $this->resolve($user, $ref, Capability::NotionRead);

        if ($account === null) {
            return [];
        }

        try {
            $comments = $this->notion->get($account, 'comments', ['block_id' => $id, 'page_size' => 100])['results'] ?? [];
            $blocks = $this->notion->get($account, "blocks/{$id}/children", ['page_size' => self::COMMENTED_BLOCKS])['results'] ?? [];
        } catch (NotionError $exception) {
            // The connection may lack the "read comments" capability.
            report($exception);

            return [];
        }

        $quotes = [];

        foreach ($this->blockComments($account, $blocks) as $blockId => $found) {
            foreach ($found as $comment) {
                $comments[] = $comment;
                $quotes[$comment['discussion_id']] ??= $this->blockText(collect($blocks)->firstWhere('id', $blockId) ?? []);
            }
        }

        $me = $account->metadata['user_id'] ?? null;
        $threads = collect($comments)->sortBy('created_time')->groupBy('discussion_id');

        return $threads->map(function ($thread, string $discussion) use ($me, $quotes): DocumentComment {
            $first = $thread->first();
            $reply = fn (array $comment): array => [
                'id' => $comment['id'],
                'author' => $comment['display_name']['resolved_name'] ?? 'Alguien',
                'mine' => $me !== null && ($comment['created_by']['id'] ?? null) === $me,
                'content' => self::plain($comment['rich_text'] ?? []),
                'created_at' => CarbonImmutable::parse($comment['created_time']),
            ];
            $root = $reply($first);

            return new DocumentComment(
                id: $discussion,
                author: $root['author'],
                mine: $root['mine'],
                content: $root['content'],
                quote: $quotes[$discussion] ?? null,
                createdAt: $root['created_at'],
                replies: $thread->slice(1)->map($reply)->values()->all(),
            );
        })->values()->all();
    }

    public function allows(User $user, string $ref, Capability $capability): bool
    {
        return $this->resolve($user, $ref, $capability)[0] !== null;
    }

    public function create(User $user, string $parentRef, string $title, string $markdown): WikiPageSummary
    {
        [$account, $parent] = $this->resolve($user, $parentRef, Capability::NotionEdit);

        if ($account === null) {
            throw new EditNotPossible('Esta cuenta de Notion no tiene permiso para crear páginas. Actívalo en Configuración.');
        }

        try {
            $page = $this->notion->post($account, 'pages', [
                'parent' => ['page_id' => $parent],
                'properties' => ['title' => ['title' => [['text' => ['content' => $title]]]]],
                'markdown' => $markdown,
            ]);
        } catch (NotionError $exception) {
            throw new EditNotPossible("Notion no ha creado la página: {$exception->getMessage()}");
        }

        return $this->summary($account, $page);
    }

    public function edit(User $user, string $ref, array $edits): void
    {
        [$account, $id] = $this->resolve($user, $ref, Capability::NotionEdit);

        if ($account === null) {
            throw new EditNotPossible('Esta cuenta de Notion no tiene permiso para editar páginas. Actívalo en Configuración.');
        }

        $anchored = array_values(array_filter($edits, fn (WikiEdit $edit): bool => $edit->type !== 'append'));
        $appended = array_values(array_filter($edits, fn (WikiEdit $edit): bool => $edit->type === 'append'));

        try {
            if ($anchored !== []) {
                $this->notion->patch($account, "pages/{$id}/markdown", [
                    'type' => 'update_content',
                    'update_content' => ['content_updates' => array_map(fn (WikiEdit $edit): array => ['old_str' => $edit->find, 'new_str' => $edit->replacement()], $anchored)],
                ]);
            }

            foreach ($appended as $edit) {
                $this->notion->patch($account, "pages/{$id}/markdown", [
                    'type' => 'insert_content',
                    'insert_content' => ['content' => $edit->text, 'position' => ['type' => 'end']],
                ]);
            }
        } catch (NotionError $exception) {
            throw new EditNotPossible($exception->notionCode === 'validation_error'
                ? 'La página ha cambiado desde que propuse el cambio y ya no encuentro el texto. Vuelve a pedírmelo.'
                : "Notion no ha aceptado el cambio: {$exception->getMessage()}");
        }
    }

    public function comment(User $user, string $ref, string $content, ?string $threadId = null): string
    {
        [$account, $id] = $this->resolve($user, $ref, Capability::NotionComment);

        if ($account === null) {
            throw new EditNotPossible('Esta cuenta de Notion no tiene permiso para comentar. Actívalo en Configuración.');
        }

        try {
            $comment = $this->notion->post($account, 'comments', [
                ...($threadId ? ['discussion_id' => $threadId] : ['parent' => ['page_id' => $id]]),
                'markdown' => $content,
            ]);
        } catch (NotionError $exception) {
            throw new EditNotPossible("Notion no ha aceptado el comentario: {$exception->getMessage()}");
        }

        return (string) $comment['id'];
    }

    /**
     * Inline comments of the first blocks, read together.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return array<string, list<array<string, mixed>>> Block id => comments.
     */
    private function blockComments(ConnectedAccount $account, array $blocks): array
    {
        $ids = array_values(array_filter(array_column($blocks, 'id')));

        if ($ids === []) {
            return [];
        }

        $token = $this->tokens->for($account);
        $responses = Http::pool(fn (Pool $pool): array => array_map(
            fn (string $id): mixed => $pool->as($id)->withToken($token)->withHeaders(['Notion-Version' => NotionApi::VERSION])
                ->acceptJson()->timeout(10)->get('https://api.notion.com/v1/comments', ['block_id' => $id]),
            $ids,
        ));

        $comments = [];

        foreach ($responses as $id => $response) {
            if ($response instanceof Response && $response->successful() && ($found = $response->json('results', [])) !== []) {
                $comments[$id] = $found;
            }
        }

        return $comments;
    }

    /**
     * The account a ref belongs to (if it may use the capability) and the
     * page id; for a link or a bare id, no account.
     *
     * @return array{?ConnectedAccount, ?string}
     */
    private function resolve(User $user, string $reference, Capability $capability): array
    {
        if (preg_match('/^(\d+):([0-9a-f-]{32,36})$/i', $reference, $match)) {
            return [$user->accountsWith($capability)->firstWhere('id', (int) $match[1]), $match[2]];
        }

        return [null, self::idFrom($reference)];
    }

    /**
     * The page id in a Notion link ("…/Plan-de-lanzamiento-1a2b…") or a bare id.
     */
    public static function idFrom(string $reference): ?string
    {
        $path = (string) (parse_url($reference, PHP_URL_PATH) ?? $reference);

        if (! preg_match('/([0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12})(?:[^0-9a-f]|$)/i', $path, $match)) {
            return null;
        }

        $hex = str_replace('-', '', strtolower($match[1]));

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }

    /**
     * Child pages directly under this one, at most `$limit`.
     *
     * @return list<array{account: ConnectedAccount, id: string, summary: WikiPageSummary}>
     */
    private function childPages(ConnectedAccount $account, string $pageId, string $parentTitle, int $limit): array
    {
        $blocks = $this->notion->get($account, "blocks/{$pageId}/children", ['page_size' => 50])['results'] ?? [];
        $children = [];

        foreach ($blocks as $block) {
            if (($block['type'] ?? null) !== 'child_page' || ! is_string($block['id'] ?? null)) {
                continue;
            }

            $children[] = [
                'account' => $account,
                'id' => $block['id'],
                'summary' => new WikiPageSummary(
                    ref: "{$account->id}:{$block['id']}",
                    title: is_string($block['child_page']['title'] ?? null) ? $block['child_page']['title'] : 'Sin título',
                    icon: null,
                    url: null,
                    workspace: self::workspace($account),
                    parentTitle: $parentTitle,
                ),
            ];

            if (count($children) >= $limit) {
                break;
            }
        }

        return $children;
    }

    /**
     * @param  array<string, mixed>  $page
     */
    private function summary(ConnectedAccount $account, array $page): WikiPageSummary
    {
        return new WikiPageSummary(
            ref: "{$account->id}:{$page['id']}",
            title: self::title($page),
            icon: $page['icon']['emoji'] ?? null,
            url: $page['url'] ?? null,
            workspace: self::workspace($account),
            editedAt: isset($page['last_edited_time']) ? CarbonImmutable::parse($page['last_edited_time']) : null,
        );
    }

    /**
     * @param  array<string, mixed>  $page
     */
    private static function title(array $page): string
    {
        foreach ($page['properties'] ?? [] as $property) {
            if (($property['type'] ?? null) === 'title') {
                return self::plain($property['title'] ?? []) ?: 'Sin título';
            }
        }

        return 'Sin título';
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function blockText(array $block): ?string
    {
        $text = self::plain($block[$block['type'] ?? '']['rich_text'] ?? []);

        return $text !== '' ? Str::limit($text, 140) : null;
    }

    /**
     * @param  list<array<string, mixed>>  $richText
     */
    private static function plain(array $richText): string
    {
        return trim(implode('', array_column($richText, 'plain_text')));
    }

    private static function workspace(ConnectedAccount $account): string
    {
        return (string) ($account->metadata['workspace_name'] ?? $account->email ?? 'Notion');
    }
}
