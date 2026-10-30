<?php

namespace App\Agents;

use App\Models\SenseAgent;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Every agent a person can delegate to: the ones in config/agents.php, and
 * each agent of their Archive as an agent of its own ("archivo:<slug>"), with
 * its instructions, its preloaded skills and the model its `model:` asks for.
 */
class AgentRegistry
{
    public const ARCHIVE = 'archivo:';

    public function find(User $user, string $name): ?AgentDefinition
    {
        if (str_starts_with($name, self::ARCHIVE)) {
            $agent = $user->senseAgents()->where('slug', Str::after($name, self::ARCHIVE))->first();

            return $agent ? $this->archive($agent) : null;
        }

        $config = config("agents.agents.{$name}");

        return is_array($config) ? AgentDefinition::fromConfig($name, $config) : null;
    }

    /**
     * The agents the orchestrator may delegate to (not itself).
     *
     * @return list<AgentDefinition>
     */
    public function workers(User $user): array
    {
        $configured = array_map(
            fn (string $name): AgentDefinition => AgentDefinition::fromConfig($name, config("agents.agents.{$name}")),
            array_values(array_diff(array_keys(config('agents.agents')), ['orquestador'])),
        );

        return [...$configured, ...$user->senseAgents()->orderBy('name')->get()->map($this->archive(...))->all()];
    }

    public function orchestrator(): AgentDefinition
    {
        return AgentDefinition::fromConfig('orquestador', config('agents.agents.orquestador'));
    }

    private function archive(SenseAgent $agent): AgentDefinition
    {
        $provider = (string) config('agents.provider');

        return new AgentDefinition(
            name: self::ARCHIVE.$agent->slug,
            label: $agent->name,
            description: $agent->description,
            instructions: $agent->briefing(),
            tools: array_values(array_unique(config('agents.archive.tools'))),
            provider: $provider,
            model: self::model($provider, $agent->model),
            reasoning: true,
            effort: 'low',
            queue: (string) config('agents.archive.queue'),
        );
    }

    /**
     * The model an Archive agent asks for, as Claude Code names them: haiku is
     * the light tier; sonnet, opus or inherit the capable one; any other name is
     * taken as the model itself.
     */
    public static function model(string $provider, ?string $asked): string
    {
        $tier = match (Str::lower(trim((string) $asked))) {
            'haiku' => 'light',
            '', 'inherit', 'sonnet', 'opus' => 'capable',
            default => null,
        };

        return $tier === null ? trim((string) $asked) : (string) config("agents.tiers.{$provider}.{$tier}");
    }
}
