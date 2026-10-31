<?php

namespace App\Voice\Tools\Jira;

use App\Connectors\Issues\IssueSummary;
use App\Connectors\Issues\IssueTracker;
use App\Models\User;
use App\Voice\OpenUi\Views\JiraListView;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

class SearchJira implements VoiceTool
{
    /** How many by default: enough for a list on screen. */
    private const LIMIT = 30;

    public function __construct(private IssueTracker $issues) {}

    public function name(): string
    {
        return 'search_jira';
    }

    public function description(): string
    {
        return 'Busca issues de Jira y los muestra numerados: con jql (la escribes tú, siguiendo las reglas de Jira del usuario: proyectos, filtros) o con text (palabras del título o la descripción, en sus proyectos). '
            .'Sin nada, sus issues abiertos. Para ver uno entero, usa open_jira_issue ("abre el 2"). '
            .'limit: cuántos como mucho (por defecto 30, hasta 200); súbelo si pide todos o un listado largo. more: true si hay más que no se han traído.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'jql' => ['type' => 'string', 'description' => 'Consulta JQL, por ejemplo: project = CPD AND assignee = currentUser() AND statusCategory != Done.'],
                'text' => ['type' => 'string', 'description' => 'Texto a buscar.'],
                'show_on_screen' => ['type' => 'boolean'],
                'limit' => ['type' => 'integer', 'description' => 'Cuántos issues como mucho (1-200). Por defecto 30.'],
            ],
        ];
    }

    public function rules(): array
    {
        return ['jql' => ['nullable', 'string', 'max:1000'], 'text' => ['nullable', 'string', 'max:200'], 'show_on_screen' => ['nullable', 'boolean'], 'limit' => ['nullable', 'integer', 'min:1', 'max:200']];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $limit = (int) ($arguments['limit'] ?? self::LIMIT);
        // One more than asked, to know whether there are more.
        $found = $this->issues->search($user, $arguments['jql'] ?? null, $arguments['text'] ?? null, $limit + 1);
        $more = count($found) > $limit;
        $found = array_slice($found, 0, $limit);

        return new ToolResult(
            output: array_filter([
                'issues' => array_map(fn (IssueSummary $issue, int $index): array => array_filter([
                    'number' => $index + 1,
                    'issue_id' => $issue->ref,
                    'key' => $issue->key,
                    'summary' => $issue->summary,
                    'type' => $issue->type,
                    'status' => $issue->status,
                    'priority' => $issue->priority,
                    'assignee' => $issue->assignee,
                ]), $found, array_keys($found)),
                'more' => $more ?: null,
                'note' => match (true) {
                    $found === [] => 'No hay issues que encajen.',
                    $more => "Hay más de {$limit}: si los quiere todos, vuelve a buscar con un limit mayor o afina la consulta.",
                    default => null,
                },
            ]),
            canvas: ($arguments['show_on_screen'] ?? true) && $found !== []
                ? JiraListView::canvas($user, filled($arguments['text'] ?? null) ? "Jira: «{$arguments['text']}»" : 'Jira', $arguments['jql'] ?? null, $found)
                : null,
        );
    }
}
