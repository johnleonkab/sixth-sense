<?php

namespace App\Voice\OpenUi\Data;

use App\Connectors\Issues\IssueSummary;
use App\Connectors\Issues\IssueTracker;
use App\Models\User;

/**
 * Jira issues as rows: one per issue, to count by status, type, priority or
 * person, or by the day they were last updated.
 */
class JiraIssues implements Dataset
{
    public function __construct(private IssueTracker $issues) {}

    public function name(): string
    {
        return 'jira_issues';
    }

    public function description(): string
    {
        return 'Issues de Jira como filas (key, summary, type, status, status_category, priority, assignee, updated). Con jql, los de esa consulta; con text, los que lo mencionan en los proyectos de las reglas.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'jql' => ['type' => 'string', 'description' => 'Consulta JQL.'],
                'text' => ['type' => 'string', 'description' => 'Texto a buscar.'],
                'limit' => ['type' => 'number', 'description' => 'Como mucho 100.'],
            ],
        ];
    }

    public function rules(): array
    {
        return [
            'jql' => ['nullable', 'string', 'max:500'],
            'text' => ['nullable', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function fields(): array
    {
        return [
            'key' => 'text',
            'summary' => 'text',
            'type' => 'text',
            'status' => 'text',
            'status_category' => 'text',
            'priority' => 'text',
            'assignee' => 'text',
            'updated' => 'date',
        ];
    }

    public function rows(User $user, array $arguments, string $timezone): array
    {
        $found = $this->issues->search($user, $arguments['jql'] ?? null, $arguments['text'] ?? null, (int) ($arguments['limit'] ?? 100));

        return array_map(fn (IssueSummary $issue): array => [
            'key' => $issue->key,
            'summary' => $issue->summary,
            'type' => $issue->type,
            'status' => $issue->status,
            'status_category' => $issue->statusCategory,
            'priority' => $issue->priority,
            'assignee' => $issue->assignee ?? 'Sin asignar',
            'updated' => $issue->updatedAt?->setTimezone($timezone)->toDateString(),
        ], $found);
    }
}
