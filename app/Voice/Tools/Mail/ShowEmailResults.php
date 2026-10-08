<?php

namespace App\Voice\Tools\Mail;

use App\Connectors\Mail\FoundMessage;
use App\Connectors\Mail\InvalidMailPattern;
use App\Connectors\Mail\MailSearch;
use App\Connectors\Mail\MailSearchResult;
use App\Models\User;
use App\Voice\Tools\Canvas;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Searches email and shows the results on screen, each with where it matched.
 */
class ShowEmailResults implements VoiceTool
{
    use SearchesMail;

    public function name(): string
    {
        return 'show_email_results';
    }

    public function description(): string
    {
        return 'Busca emails y muestra los resultados en pantalla, numerados, con el fragmento donde aparece lo buscado. '
            .'Úsala cuando pida buscar, ver o listar emails ("búscame el email donde Marta habla del prepago", "enséñame lo último de Grupo Vela", "¿qué emails tengo sin leer?"). '
            .'Te devuelve los mismos resultados: no los leas todos, di cuántos hay y destaca el más relevante. '
            .'Si luego pide abrir uno ("abre el 2"), usa open_email con su email_id.';
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        try {
            $search = $this->mailSearch($arguments, $context);
        } catch (InvalidMailPattern $exception) {
            return ToolResult::error($exception->getMessage());
        }

        $result = $this->mailbox->search($user, $search);

        return new ToolResult(
            output: [...$this->summary($result, $search, $context), 'shown_on_screen' => true],
            canvas: $this->canvas($result, $search, $context),
        );
    }

    private function canvas(MailSearchResult $result, MailSearch $search, ToolContext $context): Canvas
    {
        $count = count($result->messages);

        return new Canvas('mail.results', [
            'title' => match (true) {
                $count === 0 => 'Ningún email',
                $count === 1 => '1 email',
                default => "{$count} emails",
            },
            'search' => $search->describe(),
            'timezone' => $context->timezone(),
            'accounts' => $result->accounts,
            'failed_accounts' => $result->failedAccounts,
            'may_have_more' => $result->mayHaveMore,
            'scanned' => $result->scanned,
            'results' => array_map(fn (FoundMessage $found, int $index): array => [
                'number' => $index + 1,
                'ref' => $found->message->ref,
                'from' => $found->message->from?->toArray(),
                'subject' => $found->message->subject,
                'date' => $found->message->date->setTimezone($context->timezone())->toIso8601String(),
                'snippet' => $found->message->snippet,
                'excerpt' => $found->excerpt,
                'unread' => $found->message->unread,
                'account' => $found->message->account,
            ], $result->messages, array_keys($result->messages)),
        ]);
    }
}
