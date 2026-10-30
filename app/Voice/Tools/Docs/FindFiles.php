<?php

namespace App\Voice\Tools\Docs;

use App\Files\FindAcross;
use App\Models\User;
use App\Voice\OpenUi\Views\FileResultsView;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * One search across Google Drive and Notion. The voice model does not
 * chain a second search: this tool widens itself when the titles miss.
 */
class FindFiles implements VoiceTool
{
    public function __construct(private FindAcross $files) {}

    public function name(): string
    {
        return 'find_files';
    }

    public function description(): string
    {
        return 'Busca un documento en Google Drive (Docs, Sheets y Slides) y en Notion, en una sola llamada. '
            .'Úsala cuando pidan encontrar, buscar o abrir algo de lo que se está hablando ("el PRD de prepago", "la hoja de precios", "las slides del lanzamiento"). '
            .'Pasa en queries uno o varios términos posibles según el contexto, no una frase larga. '
            .'Si ningún título encaja, la herramienta mira sola los 50 últimos de Drive y de Notion y pone delante los que coinciden por nombre. '
            .'Para abrirlo: open_document con su ref si es de Drive, open_notion_page si es de Notion.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'queries' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Términos de búsqueda, de uno a cinco. Por ejemplo ["prepago", "prd prepago"].',
                ],
                'context' => ['type' => 'string', 'description' => 'De qué se está hablando, para reconocer el nombre si la búsqueda no da con él.'],
            ],
            'required' => ['queries'],
        ];
    }

    public function rules(): array
    {
        return [
            'queries' => ['required', 'array', 'min:1', 'max:5'],
            'queries.*' => ['required', 'string', 'max:80'],
            'context' => ['nullable', 'string', 'max:300'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $found = $this->files->find($user, $arguments['queries'], (string) ($arguments['context'] ?? ''));
        $files = $found['files'];

        return new ToolResult(
            output: [
                'widened' => $found['widened'],
                'total' => count($files),
                'files' => array_map(fn (array $file, int $index): array => [
                    'number' => $index + 1,
                    'ref' => $file['ref'],
                    'title' => $file['title'],
                    'kind' => $file['kind'],
                    'source' => $file['source'],
                ], $files, array_keys($files)),
                'note' => $found['widened']
                    ? 'La búsqueda por términos no dio con el título. Estos son los últimos de Drive y de Notion; los que coinciden por nombre van primero. Si uno encaja, ábrelo. Si no, dilo.'
                    : null,
                'shown_on_screen' => true,
            ],
            canvas: FileResultsView::canvas(
                $user,
                $found['widened'] ? 'Últimos archivos' : count($files).' '.(count($files) === 1 ? 'archivo' : 'archivos'),
                $arguments['queries'],
                $files,
                $found['widened'],
            ),
        );
    }
}
