<?php

namespace App\Voice\OpenUi\Views;

use App\Connectors\Mail\FoundMessage;
use App\Connectors\Mail\MailSearch;
use App\Connectors\Mail\MailSearchResult;
use App\Models\User;
use App\Voice\OpenUi\Dates;
use App\Voice\OpenUi\ViewBuilder;
use App\Voice\Tools\Canvas;
use Carbon\CarbonImmutable;

/**
 * Emails found: what was searched, then a numbered list with where each one
 * matched. A click opens the email, highlighting the same pattern.
 */
class MailResultsView
{
    public static function canvas(User $user, MailSearch $search, MailSearchResult $result, string $timezone): Canvas
    {
        $count = count($result->messages);
        $view = new ViewBuilder(match (true) {
            $count === 0 => 'Ningún email',
            $count === 1 => '1 email',
            default => "{$count} emails",
        });
        $severalAccounts = count($result->accounts) > 1;

        $chips = self::chips($search);

        if ($chips !== []) {
            $view->add('Chips', [$chips]);
        }

        $items = [];

        foreach ($result->messages as $index => $found) {
            /** @var FoundMessage $found */
            $message = $found->message;
            $n = $index + 1;
            $excerpt = $found->excerpt;

            $items[] = [
                'n' => $n,
                'title' => $message->subject !== '' ? $message->subject : '(sin asunto)',
                'eyebrow' => ($message->from?->label() ?? 'Desconocido').($severalAccounts ? " · {$message->account}" : ''),
                'snippet' => $excerpt ? $excerpt['before'].$excerpt['match'].$excerpt['after'] : $message->snippet,
                'match' => $excerpt['match'] ?? null,
                'meta' => Dates::relative($message->date, $timezone),
                'highlight' => $message->unread ?: null,
            ];

            $view->openable($n, $message->subject, 'open_email', array_filter(['email_id' => $message->ref, 'highlight' => $search->pattern?->source]));
        }

        $empty = 'No he encontrado ningún email.'.($result->scanned > 0 ? " Revisé los {$result->scanned} más recientes que encajan con el resto de filtros." : '');
        $view->add('ItemList', [$items, $empty]);

        if ($result->mayHaveMore && $items !== []) {
            $view->add('Text', ['Puede haber más. Acota por remitente o fechas para afinar.', 'muted']);
        }

        if ($result->failedAccounts !== []) {
            $view->add('Text', ['No he podido buscar en '.implode(', ', $result->failedAccounts).'. Revísalo en Configuración.', 'warn']);
        }

        return $view->canvas($user, $severalAccounts ? 'Gmail · '.count($result->accounts).' cuentas' : 'Gmail');
    }

    /**
     * @return list<string>
     */
    private static function chips(MailSearch $search): array
    {
        $short = fn (string $date): string => str_replace('.', '', CarbonImmutable::parse($date)->locale('es')->isoFormat('D MMM'));
        $chips = [];

        foreach ($search->describe() as $key => $value) {
            $chips[] = match ($key) {
                'from' => "de {$value}",
                'to' => "para {$value}",
                'subject' => "asunto: {$value}",
                'text' => "«{$value}»",
                'pattern' => "/{$value}/",
                'after' => 'desde '.$short($value),
                'before' => 'hasta '.$short($value),
                'has_attachment' => 'con adjuntos',
                'unread_only' => 'sin leer',
                'folder' => $value === 'sent' ? 'enviados' : 'recibidos',
                default => (string) $value,
            };
        }

        return $chips;
    }
}
