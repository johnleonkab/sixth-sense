<?php

namespace App\Comms\Models;

use RuntimeException;

/**
 * Scripted completions for tests. An empty script, or a listed provider, throws
 * instead of inventing an answer.
 */
class FakeCompleter implements CompletesTasks
{
    /**
     * @var list<ModelCompletion>
     */
    public array $script = [];

    /**
     * Providers that throw before a scripted completion is consumed.
     *
     * @var list<string>
     */
    public array $failures = [];

    /**
     * @var list<array{provider: string, tier: string, system: string, input: string, schema: array<string, mixed>}>
     */
    public array $calls = [];

    public function complete(string $provider, string $tier, string $system, string $input, array $schema): ModelCompletion
    {
        $this->calls[] = [
            'provider' => $provider,
            'tier' => $tier,
            'system' => $system,
            'input' => $input,
            'schema' => $schema,
        ];

        if (in_array($provider, $this->failures, true)) {
            throw new RuntimeException("Provider [{$provider}] is down.");
        }

        if ($this->script === []) {
            throw new RuntimeException('Fake completer has no scripted completion.');
        }

        return array_shift($this->script);
    }
}
