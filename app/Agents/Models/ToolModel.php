<?php

namespace App\Agents\Models;

use App\Agents\AgentDefinition;
use App\Ai\AiUnavailable;

/**
 * A model that can call tools, for one round of an agent's loop.
 *
 * Messages are neutral: role system, user, assistant (with its tool_calls and
 * reasoning) or tool (with tool_call_id, name and the result as content).
 */
interface ToolModel
{
    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array{name: string, description: string, parameters: array<string, mixed>}>  $tools
     *
     * @throws AiUnavailable when no model of the agent can answer now.
     */
    public function turn(AgentDefinition $agent, array $messages, array $tools): ModelTurn;
}
