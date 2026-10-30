<?php

namespace App\Voice\OpenUi\Views;

use App\Models\User;
use App\Voice\OpenUi\ViewBuilder;
use App\Voice\Tools\Canvas;

/**
 * Files found in Drive and Notion together, numbered with their kind and
 * account. A click opens a Notion page or a Google file.
 */
class FileResultsView
{
    private const KINDS = ['doc' => 'Doc', 'sheet' => 'Hoja', 'slide' => 'Slides', 'notion' => 'Notion'];

    /**
     * @param  list<string>  $queries
     * @param  list<array{ref: string, title: string, kind: string, source: string, account?: ?string}>  $files
     */
    public static function canvas(User $user, string $title, array $queries, array $files, bool $widened): Canvas
    {
        $view = new ViewBuilder($title);

        $view->add('Text', [$widened ? 'No estaba en la búsqueda. Estos son los últimos, y arriba los que coinciden por nombre.' : implode(' · ', array_map(fn (string $query): string => "«{$query}»", $queries)), 'muted']);

        $items = [];

        foreach ($files as $index => $file) {
            $n = $index + 1;
            $notion = $file['kind'] === 'notion';

            $items[] = [
                'n' => $n,
                'title' => $file['title'],
                'eyebrow' => self::KINDS[$file['kind']] ?? $file['kind'],
                'meta' => $file['account'] ?? null,
            ];

            $view->openable($n, $file['title'], $notion ? 'open_notion_page' : 'open_document', $notion ? ['page' => $file['ref']] : ['document' => $file['ref']]);
        }

        $view->add('ItemList', [$items, 'No hay archivos que coincidan.']);

        return $view->canvas($user, 'Drive · Notion');
    }
}
