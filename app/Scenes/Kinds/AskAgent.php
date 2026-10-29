<?php

namespace App\Scenes\Kinds;

use App\Ai\AiGenerationContext;
use App\Models\Scene;
use App\Models\SenseAgent;
use App\Models\User;
use App\Scenes\SceneBoard;
use App\Scenes\SceneText;

/**
 * One question to one agent. The answer uses that agent's briefing,
 * references included. Nothing is proposed for writing.
 */
class AskAgent implements SceneKind
{
    public function __construct(private SceneBoard $board, private SceneText $text) {}

    public function type(): string
    {
        return 'ask_agent';
    }

    public function title(): string
    {
        return 'Pregunta al agente';
    }

    public function blank(array $input): array
    {
        return [SceneBoard::card('answer', 'Respuesta')];
    }

    public function run(Scene $scene): void
    {
        $context = AiGenerationContext::forScene($scene);
        $question = trim((string) ($scene->input['question'] ?? ''));
        $agent = $this->agent($scene->user, $scene->input, $question, $context);

        if ($agent === null) {
            $this->board->fill($scene, 'answer', [
                'state' => 'failed',
                'error' => 'Ningún agente encaja con esa pregunta.',
            ], error: 'Ningún agente encaja con esa pregunta.');

            return;
        }

        $answer = $this->text->capable(
            'Respondes como el agente cuyo briefing te doy. Una respuesta breve, en español. Devuelve json con la clave answer.',
            "Briefing:\n{$agent->briefing()}\n\nPregunta:\n{$question}",
            [
                'type' => 'object',
                'properties' => ['answer' => ['type' => 'string']],
                'required' => ['answer'],
            ],
            $context,
        );

        $text = trim((string) ($answer['answer'] ?? ''));

        $this->board->fill($scene, 'answer', [
            'state' => $text === '' ? 'failed' : 'ready',
            'text' => $text,
            'error' => $text === '' ? 'El agente no ha contestado.' : null,
            'title' => $agent->name,
        ], error: $text === '' ? 'El agente no ha contestado.' : null);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function agent(User $user, array $input, string $question, AiGenerationContext $context): ?SenseAgent
    {
        $named = $input['slug'] ?? null;

        if (is_string($named) && $named !== '') {
            return $user->senseAgents()->where('slug', $named)->first();
        }

        $agents = $user->senseAgents()->orderBy('name')->get();

        if ($agents->isEmpty()) {
            return null;
        }

        $list = $agents->map(fn (SenseAgent $agent): string => "- {$agent->slug}: {$agent->cue}")->implode("\n");
        $picked = $this->text->light(
            'Elige el slug del agente cuyo cuándo encaja con la pregunta. Si ninguno encaja, devuelve slug vacío. Devuelve json.',
            "Pregunta:\n{$question}\n\nAgentes:\n{$list}",
            [
                'type' => 'object',
                'properties' => ['slug' => ['type' => 'string']],
                'required' => ['slug'],
            ],
            $context,
        );

        $slug = trim((string) ($picked['slug'] ?? ''));

        if ($slug === '') {
            return null;
        }

        return $agents->firstWhere('slug', $slug);
    }
}
