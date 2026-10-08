<?php

namespace App\Voice\Tools\Mail;

use App\Connectors\Mail\InvalidMailPattern;
use App\Models\User;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Searches email to answer by voice, without changing the screen.
 */
class SearchEmails implements VoiceTool
{
    use SearchesMail;

    public function name(): string
    {
        return 'search_emails';
    }

    public function description(): string
    {
        return 'Busca emails del usuario para responder SOLO por voz, sin mostrar nada. '
            .'Úsala para preguntas concretas: "¿me ha contestado Andrés?", "¿qué dijo Pablo de los reembolsos?". '
            .'Si pide ver, buscar en pantalla o listar emails, usa show_email_results; para leer uno entero, open_email. '
            .'Al menos un filtro es obligatorio.';
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        if (array_filter($arguments) === []) {
            return ToolResult::error('Indica al menos un filtro: remitente, texto, patrón, asunto o fechas.');
        }

        try {
            $search = $this->mailSearch($arguments, $context);
        } catch (InvalidMailPattern $exception) {
            return ToolResult::error($exception->getMessage());
        }

        return new ToolResult($this->summary($this->mailbox->search($user, $search), $search, $context));
    }
}
