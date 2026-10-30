<?php

namespace App\Voice\OpenUi\Views;

use App\Connectors\Wiki\WikiPageSummary;
use App\Models\User;
use App\Voice\OpenUi\Dates;
use App\Voice\OpenUi\ViewBuilder;
use App\Voice\Tools\Canvas;

/**
 * Notion pages found, numbered, with where each one lives and when it was
 * last edited. A click opens the page.
 */
class NotionResultsView
{
    /**
     * @param  list<WikiPageSummary>  $pages
     */
    public static function canvas(User $user, string $title, array $pages, string $timezone): Canvas
    {
        $view = new ViewBuilder($title);
        $items = [];

        foreach ($pages as $index => $page) {
            $n = $index + 1;

            $items[] = [
                'n' => $n,
                'title' => trim(($page->icon ? "{$page->icon} " : '').$page->title),
                'eyebrow' => $page->parentTitle ? "en {$page->parentTitle}" : $page->workspace,
                'meta' => $page->editedAt ? 'editada '.Dates::relative($page->editedAt, $timezone) : null,
            ];

            $view->openable($n, $page->title, 'open_notion_page', ['page' => $page->ref]);
        }

        $view->add('ItemList', [$items, 'No hay páginas con ese título.']);

        return $view->canvas($user, 'Notion', 'focus');
    }
}
