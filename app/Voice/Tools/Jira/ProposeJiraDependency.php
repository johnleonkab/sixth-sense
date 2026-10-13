<?php

namespace App\Voice\Tools\Jira;

use App\Connections\Capability;
use App\Models\PendingAction;
use App\Models\User;
use App\Voice\Tools\Canvas;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;
use RuntimeException;

/**
 * Proposes marking that one issue blocks another, or removing a dependency.
 */
class ProposeJiraDependency implements VoiceTool
{
    use UsesIssues;

    public function name(): string
    {
        return 'propose_jira_dependency';
    }

    public function description(): string
    {
        return 'Propone marcar una dependencia entre dos issues de Jira (blocker bloquea a blocked, con el tipo de enlace de las reglas del usuario) o quitar una (remove con su link_id, de open_jira_issue). '
            .'Se muestra como borrador y NO cambia nada hasta que el usuario lo confirme. Decide quién bloquea a quién según las reglas de dependencias del usuario.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['add', 'remove']],
                'blocker' => ['type' => 'string', 'description' => 'Clave del issue que bloquea (add).'],
                'blocked' => ['type' => 'string', 'description' => 'Clave del issue bloqueado (add).'],
                'link_id' => ['type' => 'string', 'description' => 'link_id de la dependencia a quitar (remove).'],
                'issue' => ['type' => 'string', 'description' => 'Clave del issue al que pertenece link_id (remove).'],
            ],
            'required' => ['action'],
        ];
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'in:add,remove'],
            'blocker' => ['required_if:action,add', 'nullable', 'string', 'max:300'],
            'blocked' => ['required_if:action,add', 'nullable', 'string', 'max:300'],
            'link_id' => ['required_if:action,remove', 'nullable', 'string', 'max:100'],
            'issue' => ['required_if:action,remove', 'nullable', 'string', 'max:300'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        try {
            if ($arguments['action'] === 'remove') {
                $issue = $this->issueNamed($user, $arguments['issue']);
                $link = collect($issue->links)->first(fn (array $link): bool => in_array($arguments['link_id'], [$link['ref'], $link['id']], true))
                    ?? throw new RuntimeException("{$issue->summary->key} no tiene esa dependencia. Ábrelo de nuevo para ver sus link_id.");

                $this->guard($user, $issue->summary->ref);
                $action = $this->actions->propose($user, 'jira.unlink', "Quitar «{$issue->summary->key} {$link['relation']} {$link['key']}»", ['link' => $link['ref']]);

                return $this->draft($action, 'unlink', ['from' => $issue->summary->key, 'relation' => $link['relation'], 'to' => $link['key'], 'to_summary' => $link['summary']]);
            }

            $blocker = $this->issueNamed($user, $arguments['blocker']);
            $blocked = $this->issueNamed($user, $arguments['blocked']);

            if (self::siteOf($blocker->summary->ref) !== self::siteOf($blocked->summary->ref)) {
                throw new RuntimeException('Los dos issues están en Jira distintos: no se pueden enlazar.');
            }

            $this->guard($user, $blocker->summary->ref);
        } catch (RuntimeException $exception) {
            return ToolResult::error($exception->getMessage());
        }

        $action = $this->actions->propose($user, 'jira.link', "{$blocker->summary->key} bloquea a {$blocked->summary->key}", ['blocker' => $blocker->summary->ref, 'blocked' => $blocked->summary->ref]);

        return $this->draft($action, 'link', [
            'from' => $blocker->summary->key,
            'from_summary' => $blocker->summary->summary,
            'relation' => 'bloquea a',
            'to' => $blocked->summary->key,
            'to_summary' => $blocked->summary->summary,
        ]);
    }

    /**
     * @throws RuntimeException
     */
    private function guard(User $user, string $ref): void
    {
        if (! $this->issues->allows($user, $ref, Capability::JiraWrite)) {
            throw new RuntimeException('Esta cuenta de Jira no tiene permiso para editar issues. Puede activarlo en Configuración (Conexiones).');
        }
    }

    /**
     * @param  array<string, mixed>  $dependency
     */
    private function draft(PendingAction $action, string $kind, array $dependency): ToolResult
    {
        return new ToolResult(
            output: $this->proposalOutput($action, ['change' => $action->label]),
            canvas: new Canvas('jira.draft', ['action' => $action->toState(), 'kind' => $kind, 'dependency' => $dependency]),
        );
    }
}
