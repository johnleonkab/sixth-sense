<?php

namespace App\Voice\Tools\Screen;

use App\Models\User;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Pages, zoom and text of the PDF or image on screen. The browser answers it
 * itself, since that is where the file is open; it only reaches Laravel when
 * nothing on screen can.
 */
class ControlViewer implements VoiceTool
{
    public const ACTIONS = ['next_page', 'previous_page', 'go_to_page', 'zoom_in', 'zoom_out', 'fit', 'read_text'];

    public function name(): string
    {
        return 'control_viewer';
    }

    public function description(): string
    {
        return 'Controla el PDF o la imagen que hay en pantalla: next_page, previous_page, go_to_page (page), zoom_in, zoom_out, fit (ajustar al ancho) '
            .'y read_text, que te devuelve el texto de una página (page, por defecto la que se ve) o de un rango (page y to_page) para resumirlo o leerlo en voz alta. '
            .'Al leer en voz alta, lee el texto tal cual, con naturalidad, sin leer números de página ni símbolos sueltos. '
            .'Si hay dos cosas en pantalla, indica cuál con canvas (su número).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => self::ACTIONS],
                'page' => ['type' => 'integer', 'description' => 'Página, para go_to_page y read_text.'],
                'to_page' => ['type' => 'integer', 'description' => 'Última página a leer con read_text.'],
                'canvas' => ['type' => 'integer', 'description' => 'Número en pantalla del visor, si hay más de uno.'],
            ],
            'required' => ['action'],
        ];
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'in:'.implode(',', self::ACTIONS)],
            'page' => ['nullable', 'integer', 'min:1'],
            'to_page' => ['nullable', 'integer', 'min:1'],
            'canvas' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        return ToolResult::error('No hay ningún PDF ni imagen abiertos en pantalla. Ábrelo primero.');
    }
}
