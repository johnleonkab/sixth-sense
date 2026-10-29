<?php

namespace App\Voice\Tools\Scenes;

use App\Models\User;
use App\Scenes\OpenScene;
use App\Scenes\SceneKinds;
use App\Voice\Tools\Canvas;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;
use Illuminate\Validation\Rule;

/**
 * Opens a compound request. The answer is the empty screen.
 * Later pieces arrive on the user's channel, not in this result.
 */
class OpenSceneTool implements VoiceTool
{
    public function __construct(private OpenScene $scenes, private SceneKinds $kinds) {}

    public function name(): string
    {
        return 'open_scene';
    }

    public function description(): string
    {
        return 'Abre una escena para un encargo que necesita más de una herramienta. Es la ÚNICA llamada: no encadenes calendario, correo ni GitHub tú. '
            .'slot_then_reply: buscar hueco y contestar un email (email_id). '
            .'stories_from_notes: dejar las historias de unas notas en un proyecto (notes, project). '
            .'ask_agent: preguntar a un agente (question; slug si la persona lo nombra). '
            .'La pantalla se abre vacía al momento. Di una sola frase y calla. No narres los pasos.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'enum' => $this->kinds->voiceTypes(), 'description' => 'Tipo de escena, de la lista cerrada.'],
                'email_id' => ['type' => 'string', 'description' => 'Para slot_then_reply, el email al que se responde.'],
                'minutes' => ['type' => 'integer', 'description' => 'Minutos del hueco. Por defecto 30.'],
                'notes' => ['type' => 'string', 'description' => 'Para stories_from_notes, las notas de las que salen las historias.'],
                'project' => ['type' => 'string', 'description' => 'Slug del proyecto donde van las historias.'],
                'question' => ['type' => 'string', 'description' => 'Para ask_agent, la pregunta.'],
                'slug' => ['type' => 'string', 'description' => 'Slug del agente, cuando la persona lo nombra.'],
            ],
            'required' => ['type'],
        ];
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in($this->kinds->voiceTypes())],
            'email_id' => ['nullable', 'string', 'max:200', 'required_if:type,slot_then_reply'],
            'minutes' => ['nullable', 'integer', 'min:15', 'max:240'],
            'notes' => ['nullable', 'string', 'max:20000', 'required_if:type,stories_from_notes'],
            'project' => ['nullable', 'string', 'max:80', 'required_if:type,stories_from_notes'],
            'question' => ['nullable', 'string', 'max:4000', 'required_if:type,ask_agent'],
            'slug' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $input = array_filter([
            'email_id' => $arguments['email_id'] ?? null,
            'minutes' => $arguments['minutes'] ?? null,
            'notes' => $arguments['notes'] ?? null,
            'project' => $arguments['project'] ?? null,
            'question' => $arguments['question'] ?? null,
            'slug' => $arguments['slug'] ?? null,
            'timezone' => $context->timezone(),
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        $scene = $this->scenes->open($user, $arguments['type'], $input);
        $canvas = $this->scenes->canvas($scene);

        return new ToolResult(
            output: [
                'scene_id' => $scene->id,
                'type' => $scene->type,
                'status' => 'abierta',
                'instruction' => 'Di una sola frase diciendo que lo preparas en pantalla. No llames a más herramientas para este encargo. No narres los pasos. Cuando la pantalla ya tenga el resultado, puedes decirlo en una frase.',
            ],
            canvas: new Canvas('scene.board', $canvas, 'wide'),
        );
    }
}
