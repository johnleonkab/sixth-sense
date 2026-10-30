<?php

namespace App\Agents\Models;

use App\Agents\AgentDefinition;
use Closure;

/**
 * For tests and demo mode: answers from a script, per agent, in order. Each
 * turn is a ModelTurn, or a closure that builds one from the conversation.
 * An agent with nothing scripted answers at once, with no tools.
 */
class FakeToolModel implements ToolModel
{
    /** @var array<string, list<ModelTurn|Closure>> */
    private array $script = [];

    /** @var list<array{agent: string, messages: list<array<string, mixed>>, tools: list<string>}> */
    public array $calls = [];

    /**
     * @param  ModelTurn|Closure(list<array<string, mixed>>): ModelTurn  ...$turns
     */
    public function script(string $agent, ModelTurn|Closure ...$turns): self
    {
        $this->script[$agent] = [...($this->script[$agent] ?? []), ...$turns];

        return $this;
    }

    public function turn(AgentDefinition $agent, array $messages, array $tools): ModelTurn
    {
        $this->calls[] = ['agent' => $agent->name, 'messages' => $messages, 'tools' => array_column($tools, 'name')];
        $next = array_shift($this->script[$agent->name]);

        if ($next === null) {
            return new ModelTurn(text: 'Hecho.', model: 'fake');
        }

        return $next instanceof Closure ? $next($messages) : $next;
    }

    /**
     * A turn that calls one tool.
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function call(string $tool, array $arguments = []): ModelTurn
    {
        return new ModelTurn(toolCalls: [new ToolCall(uniqid('call_'), $tool, $arguments)], model: 'fake', inputTokens: 100, outputTokens: 20);
    }

    /**
     * A turn that finishes, with what to say and the references to show.
     *
     * @param  list<string>  $refs
     */
    public static function finish(string $say, array $refs = []): ModelTurn
    {
        return self::call('finish', ['say' => $say, 'refs' => $refs]);
    }
}
