<?php

namespace App\Voice\Tools\Jira;

use App\Connections\Capability;
use App\Connectors\Issues\IssueChanges;
use App\Models\User;
use App\Voice\Tools\Canvas;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Proposes changes to an issue: its name, description, priority, labels,
 * assignee or parent. Nothing changes until the person confirms.
 */
class ProposeJiraUpdate implements VoiceTool
{
    use UsesIssues;

    public function name(): string
    {
        return 'propose_jira_update';
    }

    public function description(): string
    {
        return 'Propone cambiar un issue de Jira: título, descripción (completa, en markdown), prioridad, etiquetas, persona asignada o padre (la épica). Se muestra con el antes y el después y NO cambia nada hasta que el usuario lo confirme. '
            .'Para marcar la prioridad, usa priority con el nombre que tenga en Jira. Sigue las reglas de Jira del usuario al reescribir títulos o descripciones. Abre antes el issue para ver lo que tiene.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'issue' => ['type' => 'string', 'description' => 'issue_id o clave.'],
                'summary' => ['type' => 'string'],
                'description' => ['type' => 'string', 'description' => 'La descripción completa nueva, en markdown.'],
                'priority' => ['type' => 'string'],
                'labels' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Las etiquetas con las que queda (sustituye las de ahora).'],
                'assignee' => ['type' => 'string', 'description' => 'A quién asignarlo, por nombre.'],
                'parent' => ['type' => 'string', 'description' => 'Clave de la nueva épica padre.'],
            ],
            'required' => ['issue'],
        ];
    }

    public function rules(): array
    {
        return [
            'issue' => ['required', 'string', 'max:300'],
            'summary' => ['nullable', 'string', 'max:250'],
            'description' => ['nullable', 'string', 'max:30000'],
            'priority' => ['nullable', 'string', 'max:60'],
            'labels' => ['nullable', 'array', 'max:10'],
            'labels.*' => ['string', 'max:60', 'regex:/^\S+$/'],
            'assignee' => ['nullable', 'string', 'max:120'],
            'parent' => ['nullable', 'string', 'regex:/^[A-Z][A-Z0-9_]{1,9}-\d+$/'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        try {
            $issue = $this->issueNamed($user, $arguments['issue']);
            $ref = $issue->summary->ref;

            if (! $this->issues->allows($user, $ref, Capability::JiraWrite)) {
                throw new RuntimeException('Esta cuenta de Jira no tiene permiso para editar issues. Puede activarlo en Configuración (Conexiones).');
            }

            $priority = filled($arguments['priority'] ?? null) ? $this->priorityNamed($user, self::siteOf($ref), $arguments['priority']) : null;
            $assignee = filled($arguments['assignee'] ?? null) ? $this->peopleNamed($user, self::siteOf($ref), [$arguments['assignee']]) : [];
        } catch (RuntimeException $exception) {
            return ToolResult::error($exception->getMessage());
        }

        $changes = new IssueChanges(
            summary: filled($arguments['summary'] ?? null) && $arguments['summary'] !== $issue->summary->summary ? $arguments['summary'] : null,
            description: $arguments['description'] ?? null,
            priority: $priority !== $issue->summary->priority ? $priority : null,
            labels: $arguments['labels'] ?? null,
            assigneeId: $assignee ? array_values($assignee)[0] : null,
            parentKey: $arguments['parent'] ?? null,
        );

        if ($changes->isEmpty()) {
            return ToolResult::error('No has indicado ningún cambio para el issue (o ya está así).');
        }

        $diff = array_values(array_filter([
            $changes->summary ? ['label' => 'Título', 'before' => $issue->summary->summary, 'after' => $changes->summary] : null,
            $changes->priority ? ['label' => 'Prioridad', 'before' => $issue->summary->priority, 'after' => $changes->priority] : null,
            $changes->labels !== null ? ['label' => 'Etiquetas', 'before' => implode(', ', $issue->labels) ?: null, 'after' => implode(', ', $changes->labels) ?: '—'] : null,
            $changes->assigneeId ? ['label' => 'Asignado', 'before' => $issue->summary->assignee, 'after' => array_key_first($assignee)] : null,
            $changes->parentKey ? ['label' => 'Padre', 'before' => $issue->parent['key'] ?? null, 'after' => $changes->parentKey] : null,
            $changes->description !== null ? ['label' => 'Descripción', 'before' => Str::limit($issue->descriptionText, 600) ?: null, 'after' => Str::limit($changes->description, 3000)] : null,
        ]));

        $action = $this->actions->propose($user, 'jira.update', "Editar {$issue->summary->key}", ['ref' => $ref, 'changes' => $changes->toArray()]);

        return new ToolResult(
            output: $this->proposalOutput($action, ['issue' => $issue->summary->key, 'changes' => implode('; ', array_column($diff, 'label'))]),
            canvas: new Canvas('jira.draft', [
                'action' => $action->toState(),
                'kind' => 'update',
                'target' => $issue->summary->toArray($context->timezone()),
                'changes' => $diff,
            ], layout: 'wide'),
        );
    }

    /**
     * @throws RuntimeException
     */
    private function priorityNamed(User $user, int $site, string $wanted): string
    {
        $priorities = $this->issues->priorities($user, $site);

        return collect($priorities)->first(fn (string $priority): bool => Str::lower($priority) === Str::lower($wanted))
            ?? ($priorities === [] ? $wanted : throw new RuntimeException("No existe la prioridad «{$wanted}». Las hay: ".implode(', ', $priorities).'.'));
    }
}
