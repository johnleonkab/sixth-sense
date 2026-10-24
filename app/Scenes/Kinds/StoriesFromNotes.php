<?php

namespace App\Scenes\Kinds;

use App\Actions\Actions;
use App\Connectors\GitHub\GithubContents;
use App\Jobs\RunSceneStep;
use App\Models\GithubRepository;
use App\Models\Scene;
use App\Models\SenseAgent;
use App\Models\User;
use App\Scenes\SceneBoard;
use App\Scenes\SceneText;
use App\Sense\DocumentRules;
use App\Sense\FileRules;
use Illuminate\Support\Str;

/**
 * Notes become one proposed file per title. The path and the front matter
 * come from the prd type. A file that breaks the rules stays an error.
 */
class StoriesFromNotes implements SceneKind
{
    public function __construct(
        private SceneBoard $board,
        private SceneText $text,
        private GithubContents $contents,
        private FileRules $rules,
        private Actions $actions,
    ) {}

    public function type(): string
    {
        return 'stories_from_notes';
    }

    public function title(): string
    {
        return 'Historias';
    }

    public function blank(array $input): array
    {
        return [SceneBoard::card('list', 'Historias')];
    }

    public function run(Scene $scene): void
    {
        $notes = trim((string) ($scene->input['notes'] ?? ''));
        $project = (string) ($scene->input['project'] ?? '');
        $agent = $this->agent($scene->user, $scene->input, $notes);

        if ($agent === null) {
            $this->board->fill($scene, 'list', [
                'state' => 'failed',
                'error' => 'No hay un agente para escribir las historias.',
            ], error: 'No hay un agente para escribir las historias.');

            return;
        }

        $repository = $scene->user->workRepository();

        if ($repository === null) {
            $this->board->fill($scene, 'list', [
                'state' => 'failed',
                'error' => 'No hay un archivo de trabajo.',
            ], error: 'No hay un archivo de trabajo.');

            return;
        }

        $picked = $this->text->capable(
            'Sacas los títulos de las historias de usuario que hay en las notas. No escribas el cuerpo. Devuelve json con la clave titles, una lista de frases cortas.',
            "Briefing:\n{$agent->briefing()}\n\nNotas:\n{$notes}",
            [
                'type' => 'object',
                'properties' => [
                    'titles' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['titles'],
            ],
        );

        $titles = array_values(array_filter(
            $picked['titles'] ?? [],
            fn (mixed $title): bool => is_string($title) && trim($title) !== '',
        ));

        if ($titles === []) {
            $this->board->fill($scene, 'list', [
                'state' => 'failed',
                'error' => 'No salen historias de esas notas.',
            ], error: 'No salen historias de esas notas.');

            return;
        }

        $cards = [];
        $used = [];

        foreach ($titles as $title) {
            $key = $this->key($title, $used);
            $used[] = $key;
            $cards[] = SceneBoard::card($key, $title);
            $scene->steps()->create([
                'layer' => 2,
                'key' => $key,
                'status' => 'pending',
                'payload' => ['title' => $title, 'project' => $project, 'agent' => $agent->slug],
            ]);
        }

        $this->board->store($scene, $this->title(), $cards);

        foreach ($cards as $card) {
            RunSceneStep::dispatch($scene->id, $card['key']);
        }
    }

    public function write(Scene $scene, string $key): void
    {
        $step = $scene->steps()->where('key', $key)->first();
        $repository = $scene->user->workRepository();
        $title = (string) ($step?->payload['title'] ?? '');
        $project = (string) ($step?->payload['project'] ?? '');
        $agent = $scene->user->senseAgents()->where('slug', $step?->payload['agent'] ?? '')->first();

        if ($step === null || $repository === null || $agent === null || $title === '') {
            $this->board->fill($scene, $key, [
                'state' => 'failed',
                'error' => 'No puedo preparar esta historia.',
            ], error: 'No puedo preparar esta historia.');

            return;
        }

        $draft = $this->text->capable(
            'Escribes una historia de usuario. Devuelve json con why, outcome y body. El título ya está decidido. En español.',
            "Briefing:\n{$agent->briefing()}\n\nNotas:\n{$scene->input['notes']}\n\nTítulo:\n{$title}",
            [
                'type' => 'object',
                'properties' => [
                    'why' => ['type' => 'string'],
                    'outcome' => ['type' => 'string'],
                    'body' => ['type' => 'string'],
                ],
                'required' => ['why', 'outcome', 'body'],
            ],
        );

        $problem = $this->propose($scene, $repository, $project, $title, $key, $agent, $draft);

        if ($problem !== null) {
            $this->board->fill($scene, $key, [
                'state' => 'failed',
                'error' => $problem,
                'text' => $title,
            ], error: $problem);
        }
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function propose(Scene $scene, GithubRepository $repository, string $project, string $title, string $key, SenseAgent $agent, array $draft): ?string
    {
        $file = $this->contents->read($repository, 'types/prd.yml');
        $spec = is_array($file) ? DocumentRules::parse($file['content']) : null;

        if ($spec === null) {
            return 'No hay reglas para el tipo prd.';
        }

        $slug = Str::slug($title);
        $path = DocumentRules::locate($spec, [
            'project' => $project,
            'slug' => $slug !== '' ? $slug : $key,
            'date' => now()->toDateString(),
        ]);

        if ($path === null) {
            return 'La ruta de la historia no cabe en el tipo prd.';
        }

        $contents = DocumentRules::compose('prd', $spec, [
            'author' => $scene->user->name,
            'why' => (string) ($draft['why'] ?? ''),
            'outcome' => (string) ($draft['outcome'] ?? ''),
        ], (string) ($draft['body'] ?? ''));

        $violation = $this->rules->violation($repository, $path, $contents);

        if ($violation !== null) {
            return $violation;
        }

        $action = $this->actions->propose($scene->user, 'sense.write', "Guardar {$path}", [
            'path' => $path,
            'contents' => $contents,
            'message' => "Crea {$path}",
        ]);

        $this->board->fill($scene, $key, [
            'state' => 'ready',
            'text' => trim((string) ($draft['body'] ?? $title)),
            'action' => $action->toState(),
        ], $action->id);

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function agent(User $user, array $input, string $notes): ?SenseAgent
    {
        $named = $input['slug'] ?? null;

        if (is_string($named) && $named !== '') {
            $agent = $user->senseAgents()->where('slug', $named)->first();

            if ($agent !== null) {
                return $agent;
            }
        }

        $agents = $user->senseAgents()->orderBy('name')->get();

        if ($agents->isEmpty()) {
            return null;
        }

        $list = $agents->map(fn (SenseAgent $agent): string => "- {$agent->slug}: {$agent->cue}")->implode("\n");
        $picked = $this->text->light(
            'Elige el slug del agente cuyo cuándo encaja con estas notas. Si ninguno encaja, devuelve slug vacío. Devuelve json.',
            "Notas:\n{$notes}\n\nAgentes:\n{$list}",
            [
                'type' => 'object',
                'properties' => ['slug' => ['type' => 'string']],
                'required' => ['slug'],
            ],
        );

        $slug = trim((string) ($picked['slug'] ?? ''));
        $matched = $slug === '' ? null : $agents->firstWhere('slug', $slug);

        return $matched ?? $agents->firstWhere('slug', 'jira-writer');
    }

    /**
     * @param  list<string>  $used
     */
    private function key(string $title, array $used): string
    {
        $base = Str::slug($title);
        $base = $base !== '' ? $base : 'historia';
        $key = $base;
        $suffix = 2;

        while (in_array($key, $used, true)) {
            $key = $base.'-'.$suffix;
            $suffix++;
        }

        return $key;
    }
}
