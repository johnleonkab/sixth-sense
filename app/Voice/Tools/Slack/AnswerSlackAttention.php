<?php

namespace App\Voice\Tools\Slack;

use App\Alerts\Intents\SlackPreference;
use App\Models\User;
use App\Voice\Tools\Canvas;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * The three cold-start answers. They enter the profile at once.
 */
class AnswerSlackAttention implements VoiceTool
{
    public function __construct(private SlackPreference $preferences) {}

    public function name(): string
    {
        return 'answer_slack_attention';
    }

    public function description(): string
    {
        return 'Guarda lo que la persona dice al arrancar, cuando aún no hay histórico de Slack. '
            .'people: a quién responde siempre. projects: proyectos de este trimestre. channels: canales que son ruido. '
            .'Pasa solo lo que haya dicho. Queda guardado, sin pedir otro sí.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'people' => ['type' => 'string', 'description' => 'Nombres, separados por comas.'],
                'projects' => ['type' => 'string', 'description' => 'Proyectos de este trimestre, separados por comas.'],
                'channels' => ['type' => 'string', 'description' => 'Canales que son ruido, separados por comas.'],
            ],
        ];
    }

    public function rules(): array
    {
        return [
            'people' => ['nullable', 'string', 'max:500'],
            'projects' => ['nullable', 'string', 'max:500'],
            'channels' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $lines = [];

        foreach ($this->parts($arguments['people'] ?? null) as $name) {
            $body = $this->preferences->phrase('always_notify', null, $name, []);
            $this->preferences->acceptNow($user, 'always_notify', $body, null, $name, []);
            $lines[] = $body;
        }

        $projects = $this->parts($arguments['projects'] ?? null);

        if ($projects !== []) {
            $words = [];

            foreach ($projects as $project) {
                foreach (preg_split('/\s+/u', $project) ?: [] as $word) {
                    if (mb_strlen($word) >= 4) {
                        $words[] = mb_strtolower($word);
                    }
                }
            }

            $body = 'Este trimestre cuenta: '.implode(', ', $projects).'.';
            $this->preferences->acceptNow($user, 'always_notify', $body, null, null, $words);
            $lines[] = $body;
        }

        foreach ($this->parts($arguments['channels'] ?? null) as $channel) {
            $label = str_starts_with($channel, '#') ? $channel : '#'.$channel;
            $body = $this->preferences->phrase('not_important', $label, null, []);
            $this->preferences->acceptNow($user, 'not_important', $body, $label, null, []);
            $lines[] = $body;
        }

        if ($lines === []) {
            return ToolResult::error('Dime a quién respondes siempre, qué proyectos son de este trimestre o qué canales son ruido.');
        }

        return new ToolResult(
            output: [
                'saved' => true,
                'rules' => $lines,
            ],
            canvas: new Canvas('slack.rule', [
                'title' => 'Reglas de atención',
                'body' => implode("\n", $lines),
                'saved' => true,
                'action' => null,
            ]),
        );
    }

    /**
     * @return list<string>
     */
    private function parts(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $parts = [];

        foreach (preg_split('/[,;]+/u', $value) ?: [] as $part) {
            $part = trim($part);

            if ($part !== '') {
                $parts[] = $part;
            }
        }

        return $parts;
    }
}
