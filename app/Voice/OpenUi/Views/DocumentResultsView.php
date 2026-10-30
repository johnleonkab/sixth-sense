<?php

namespace App\Voice\OpenUi\Views;

use App\Connectors\Docs\DocumentSearch;
use App\Connectors\Docs\DocumentSearchResult;
use App\Connectors\Docs\DocumentSummary;
use App\Models\User;
use App\Voice\OpenUi\Dates;
use App\Voice\OpenUi\ViewBuilder;
use App\Voice\Tools\Canvas;

/**
 * Documents found: what was searched, then a numbered list with who owns
 * each one and when it was last edited. A click opens the document.
 */
class DocumentResultsView
{
    public static function canvas(User $user, DocumentSearch $search, DocumentSearchResult $result, string $timezone): Canvas
    {
        $count = count($result->documents);
        $view = new ViewBuilder(match (true) {
            $count === 0 => 'Ningún documento',
            $count === 1 => '1 documento',
            default => "{$count} documentos",
        });
        $severalAccounts = count($result->accounts) > 1;
        $person = fn (?array $person): ?string => $person === null ? null : ($person['name'] ?? $person['email'] ?? null);

        $chips = array_values(array_map(fn (string $key, string $value): string => match ($key) {
            'name' => "«{$value}»",
            'text' => "contiene «{$value}»",
            'owner' => "de {$value}",
            'modified_after' => "editado desde {$value}",
            'modified_before' => "editado hasta {$value}",
            'created_after' => "creado desde {$value}",
            'created_before' => "creado hasta {$value}",
            default => $value,
        }, array_keys($search->describe()), $search->describe()));

        if ($chips !== []) {
            $view->add('Chips', [$chips]);
        }

        $items = [];

        foreach ($result->documents as $index => $document) {
            /** @var DocumentSummary $document */
            $n = $index + 1;
            $editor = $person($document->lastModifiedBy);

            $items[] = [
                'n' => $n,
                'title' => $document->title,
                'eyebrow' => implode(' · ', array_filter([$person($document->owner), $severalAccounts ? $document->account : null])) ?: null,
                'meta' => 'editado '.Dates::relative($document->modifiedAt, $timezone).($editor ? " · {$editor}" : ''),
            ];

            $view->openable($n, $document->title, 'open_document', ['document' => $document->ref]);
        }

        $view->add('ItemList', [$items, 'No he encontrado ningún documento.']);

        if ($result->mayHaveMore && $items !== []) {
            $view->add('Text', ['Puede haber más. Acota por nombre, propietario o fechas.', 'muted']);
        }

        if ($result->failedAccounts !== []) {
            $view->add('Text', ['No he podido buscar en '.implode(', ', $result->failedAccounts).'. Revísalo en Configuración.', 'warn']);
        }

        return $view->canvas($user, $severalAccounts ? 'Docs · '.count($result->accounts).' cuentas' : 'Docs');
    }
}
