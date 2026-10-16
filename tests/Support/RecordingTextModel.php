<?php

namespace Tests\Support;

use App\Ai\TextModel;
use Closure;
use RuntimeException;

/**
 * A text model that records each call and answers with a scripted JSON object.
 */
class RecordingTextModel implements TextModel
{
    /**
     * @var list<array{system: string, user: string, schema: array<string, mixed>, temperature: float}>
     */
    public array $calls = [];

    /**
     * One object is returned every time. A list of objects is returned one call at a time.
     *
     * @param  array<string, mixed>|list<array<string, mixed>>|Closure(): array<string, mixed>  $answers
     */
    public function __construct(public array|Closure $answers) {}

    public function json(string $system, string $user, array $schema, float $temperature = 0): array
    {
        $this->calls[] = [
            'system' => $system,
            'user' => $user,
            'schema' => $schema,
            'temperature' => $temperature,
        ];

        $answer = $this->answers instanceof Closure
            ? ($this->answers)()
            : (array_is_list($this->answers) ? array_shift($this->answers) : $this->answers);

        if (! is_array($answer)) {
            throw new RuntimeException('Text model returned an unexpected answer.');
        }

        return $answer;
    }
}
