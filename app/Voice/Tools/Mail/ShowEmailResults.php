<?php

namespace App\Voice\Tools\Mail;

use App\Connectors\Mail\InvalidMailPattern;
use App\Models\User;
use App\Voice\OpenUi\Views\MailResultsView;
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
            canvas: MailResultsView::canvas($user, $search, $result, $context->timezone()),
        );
    }
}
