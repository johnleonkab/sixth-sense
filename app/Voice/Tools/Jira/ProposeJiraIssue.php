<?php

namespace App\Voice\Tools\Jira;

use App\Connectors\Issues\NewIssue;
use App\Models\User;
use App\Voice\Tools\Canvas;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Proposes a new issue (an epic, a story, a task, a subtask). Nothing is
 * created until the person confirms.
 */
class ProposeJiraIssue implements VoiceTool
{
    use UsesIssues;

    public function name(): string
    {
        return 'propose_jira_issue';
    }

    public function description(): string
    {
        return 'Propone crear un issue en Jira (épica, user story, tarea, subtarea…) y lo muestra como borrador; NO lo crea hasta que el usuario lo confirme. '
            .'Escríbelo SIEMPRE siguiendo las reglas de Jira del usuario para ese sitio (cómo se titulan y describen las épicas, las historias y las subtareas, en qué idioma). '
            .'parent: la épica de una historia, o la historia de una subtarea (su clave). La descripción va en markdown (## secciones, - listas, **negrita**).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project' => ['type' => 'string', 'description' => 'Clave del proyecto (CPD).'],
                'type' => ['type' => 'string', 'description' => 'Tipo, tal como se llama en Jira: Epic, Story, Task, Subtask…'],
                'summary' => ['type' => 'string', 'description' => 'Título.'],
                'description' => ['type' => 'string', 'description' => 'Descripción en markdown.'],
                'parent' => ['type' => 'string', 'description' => 'Clave de la épica o de la historia padre.'],
                'priority' => ['type' => 'string', 'description' => 'Prioridad, tal como se llama en Jira (High, Medium…).'],
                'labels' => ['type' => 'array', 'items' => ['type' => 'string']],
                'assignee' => ['type' => 'string', 'description' => 'A quién asignarlo, por nombre.'],
                'site' => ['type' => 'string', 'description' => 'site_id o nombre del Jira, si tiene varios.'],
            ],
            'required' => ['project', 'type', 'summary'],
        ];
    }

    public function rules(): array
    {
        return [
            'project' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_]{1,9}$/'],
            'type' => ['required', 'string', 'max:60'],
            'summary' => ['required', 'string', 'max:250'],
            'description' => ['nullable', 'string', 'max:30000'],
            'parent' => ['nullable', 'string', 'regex:/^[A-Z][A-Z0-9_]{1,9}-\d+$/'],
            'priority' => ['nullable', 'string', 'max:60'],
            'labels' => ['nullable', 'array', 'max:10'],
            'labels.*' => ['string', 'max:60', 'regex:/^\S+$/'],
            'assignee' => ['nullable', 'string', 'max:120'],
            'site' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        try {
            $site = $this->siteFor($user, $arguments['site'] ?? null);
            $types = $this->issues->issueTypes($user, $site, $arguments['project']);
            $type = collect($types)->first(fn (array $type): bool => Str::lower($type['name']) === Str::lower($arguments['type']));

            if ($types !== [] && $type === null) {
                throw new RuntimeException("El proyecto {$arguments['project']} no tiene el tipo «{$arguments['type']}». Tiene: ".implode(', ', array_column($types, 'name')).'.');
            }

            if (($type['subtask'] ?? false) && blank($arguments['parent'] ?? null)) {
                throw new RuntimeException('Una subtarea necesita la clave de su issue padre (parent).');
            }

            $priority = $this->priority($user, $site, $arguments['priority'] ?? null);
            $assignee = filled($arguments['assignee'] ?? null) ? $this->peopleNamed($user, $site, [$arguments['assignee']]) : [];
        } catch (RuntimeException $exception) {
            return ToolResult::error($exception->getMessage());
        }

        $issue = new NewIssue(
            projectKey: $arguments['project'],
            type: $type['name'] ?? $arguments['type'],
            summary: $arguments['summary'],
            description: $arguments['description'] ?? null,
            parentKey: $arguments['parent'] ?? null,
            priority: $priority,
            labels: $arguments['labels'] ?? [],
            assigneeId: $assignee ? array_values($assignee)[0] : null,
        );

        $action = $this->actions->propose($user, 'jira.create', "Crear {$issue->type} en {$issue->projectKey}: {$issue->summary}", ['site' => $site, 'issue' => $issue->toArray()]);

        return new ToolResult(
            output: $this->proposalOutput($action, ['type' => $issue->type, 'project' => $issue->projectKey, 'summary' => $issue->summary]),
            canvas: new Canvas('jira.draft', [
                'action' => $action->toState(),
                'kind' => 'create',
                'issue' => [...$issue->toArray(), 'assignee' => $assignee ? array_key_first($assignee) : null],
            ], layout: 'wide'),
        );
    }

    /**
     * @throws RuntimeException
     */
    private function priority(User $user, int $site, ?string $wanted): ?string
    {
        if (blank($wanted)) {
            return null;
        }

        $priorities = $this->issues->priorities($user, $site);
        $match = collect($priorities)->first(fn (string $priority): bool => Str::lower($priority) === Str::lower($wanted));

        return $match ?? ($priorities === [] ? $wanted : throw new RuntimeException("No existe la prioridad «{$wanted}». Las hay: ".implode(', ', $priorities).'.'));
    }
}
