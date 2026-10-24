<?php

namespace Tests\Support;

use App\Scenes\SceneText;

/**
 * Scene text that records each call and answers from a script.
 * Scenes tests use this instead of DeepSeek or Gemini.
 */
class RecordingSceneText implements SceneText
{
    /**
     * @var list<array{system: string, user: string, schema: array<string, mixed>}>
     */
    public array $capableCalls = [];

    /**
     * @var list<array{system: string, user: string, schema: array<string, mixed>}>
     */
    public array $lightCalls = [];

    /**
     * @param  list<array<string, mixed>>  $capableAnswers
     * @param  list<array<string, mixed>>  $lightAnswers
     */
    public function __construct(public array $capableAnswers = [], public array $lightAnswers = []) {}

    public function capable(string $system, string $user, array $schema): array
    {
        $this->capableCalls[] = compact('system', 'user', 'schema');

        return array_shift($this->capableAnswers) ?? [];
    }

    public function light(string $system, string $user, array $schema): array
    {
        $this->lightCalls[] = compact('system', 'user', 'schema');

        return array_shift($this->lightAnswers) ?? [];
    }
}
