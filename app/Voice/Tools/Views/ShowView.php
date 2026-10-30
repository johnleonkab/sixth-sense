<?php

namespace App\Voice\Tools\Views;

use App\Models\User;
use App\Voice\OpenUi\OpenUi;
use App\Voice\Tools\Canvas;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Shows a view the assistant composed in OpenUI Lang, for data that has no
 * canvas of its own. Showing is reading: it changes nothing anywhere.
 */
class ShowView implements VoiceTool
{
    public function name(): string
    {
        return 'show_view';
    }

    public function description(): string
    {
        return 'Muestra en pantalla una vista compuesta en OpenUI Lang con los bloques View, Row, Card, Text, Metric, Table, BarChart, LineChart, Image, Document, List y Button. '
            .'Úsala para datos sin vista propia: resultados de un servidor MCP, analítica, comparativas, imágenes o archivos. El formato está en tus instrucciones.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Título corto de la vista.'],
                'source' => ['type' => 'string', 'description' => 'De dónde salen los datos ("Google Analytics", el nombre del servidor MCP…).'],
                'ui' => ['type' => 'string', 'description' => 'La vista en OpenUI Lang, empezando por root = View("título", [...]).'],
            ],
            'required' => ['title', 'ui'],
        ];
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'source' => ['nullable', 'string', 'max:80'],
            'ui' => ['required', 'string', 'max:20000'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        if (! str_contains($arguments['ui'], 'root = View(')) {
            return ToolResult::error('La vista tiene que empezar por root = View("título", [...]).');
        }

        // A rewritten list keeps opening its items: those of the last view
        // Laravel built, by the number the person sees.
        $view = [
            'title' => $arguments['title'],
            'source' => $arguments['source'] ?? 'Vista',
            'ui' => $arguments['ui'],
            'items' => str_contains($arguments['ui'], 'ItemList(') ? OpenUi::lastItems($user) : [],
        ];

        OpenUi::rememberView($user, $view);

        return new ToolResult(
            output: ['shown_on_screen' => true],
            canvas: new Canvas('openui.view', $view, 'wide'),
        );
    }
}
