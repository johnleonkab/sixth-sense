<?php

namespace App\Voice\Tools\Jira;

use App\Connectors\Issues\IssueSummary;
use App\Connectors\Issues\IssueTracker;
use App\Models\User;
use App\Voice\Tools\Canvas;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

class SearchJira implements VoiceTool
{
    public function __construct(private IssueTracker $issues) {}

    public function name(): string
    {
        return 'search_jira';
    }

    public function description(): string
    {
        return 'Busca issues de Jira y los muestra numerados: con jql (la escribes tú, siguiendo las reglas de Jira del usuario: proyectos, filtros) o con text (palabras del título o la descripción, en sus proyectos). '
            .'Sin nada, sus issues abiertos. Para ver uno entero, usa open_jira_issue ("abre el 2").';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'jql' => ['type' => 'string', 'description' => 'Consulta JQL, por ejemplo: project = CPD AND assignee = currentUser() AND statusCategory != Done.'],
                'text' => ['type' => 'string', 'description' => 'Texto a buscar.'],
                'show_on_screen' => ['type' => 'boolean'],
            ],
        ];
    }

    public function rules(): array
    {
        return ['jql' => ['nullable', 'string', 'max:1000'], 'text' => ['nullable', 'string', 'max:200'], 'show_on_screen' => ['nullable', 'boolean']];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $found = $this->issues->search($user, $arguments['jql'] ?? null, $arguments['text'] ?? null);

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
                'note' => $found === [] ? 'No hay issues que encajen.' : null,
            ]),
            canvas: ($arguments['show_on_screen'] ?? true) && $found !== []
                ? new Canvas('jira.list', [
                    'title' => filled($arguments['text'] ?? null) ? "Jira: «{$arguments['text']}»" : 'Jira',
                    'jql' => $arguments['jql'] ?? null,
                    'issues' => array_map(fn (IssueSummary $issue, int $index): array => [...$issue->toArray($context->timezone()), 'number' => $index + 1], $found, array_keys($found)),
                ], layout: 'wide')
                : null,
        );
    }
}
