<?php

namespace App\Voice;

use App\Connectors\Chat\ChatConversation;
use App\Connectors\Chat\ChatPerson;
use App\Connectors\Chat\Chats;
use App\Connectors\Issues\IssueTracker;
use App\Connectors\Jira\JiraRules;
use App\Models\User;
use App\Voice\Tools\Toolbox;
use App\WorkContext\WorkDigest;
use Carbon\CarbonImmutable;

/**
 * Provider-agnostic description of how the voice assistant behaves for a user.
 */
final readonly class AssistantProfile
{
    /**
     * @param  list<array{name: string, description: string, parameters: array<string, mixed>}>  $tools
     */
    public function __construct(
        public string $instructions,
        public array $tools = [],
    ) {}

    /**
     * Build the profile for a conversation starting at `$now`, in the user's timezone.
     */
    public static function for(User $user, CarbonImmutable $now, ?Toolbox $toolbox = null, ?Chats $chats = null, ?IssueTracker $issues = null): self
    {
        $moment = $now->locale('es');

        $instructions = trim(strtr(config('voice.assistant.instructions'), [
            ':name' => $user->name,
            ':date' => $moment->isoFormat('dddd, D [de] MMMM [de] YYYY'),
            ':time' => $moment->isoFormat('HH:mm'),
            ':timezone' => $moment->getTimezone()->getName(),
        ]));

        return new self(
            instructions: trim(implode("\n", array_filter([
                $instructions,
                $chats ? self::familiarChats($chats->familiar($user)) : '',
                self::workContext($user),
                self::sense($user),
                'Si el encargo necesita más de una herramienta (buscar hueco y contestar, dejar varias historias, preguntar a un agente), la única llamada es open_scene. No encadenes las herramientas de ese encargo.',
                'Para encontrar un documento, una hoja, unas diapositivas o una página, la única llamada es find_files, con uno o varios términos. No busques primero en Docs y luego en Notion.',
                $issues ? self::jiraRules($issues->rules($user)) : '',
            ]))),
            tools: $toolbox?->declarations() ?? [],
        );
    }

    /**
     * How each Jira site works, as the person wrote it: followed when creating,
     * editing, prioritising, linking or commenting there.
     *
     * @param  list<array{account_id: int, site: string, rules: JiraRules}>  $sites
     */
    private static function jiraRules(array $sites): string
    {
        $written = array_filter($sites, fn (array $site): bool => trim($site['rules']->text) !== '');

        if ($written === []) {
            return '';
        }

        return "Reglas de Jira que el usuario ha escrito para cada sitio. Síguelas siempre al crear, editar, priorizar, enlazar o comentar ahí, y úsalas para saber en qué proyectos buscar:\n"
            .implode("\n\n", array_map(fn (array $site): string => "--- Jira «{$site['site']}» (site_id {$site['account_id']}) ---\n".$site['rules']->forAssistant(), $written));
    }

    /**
     * Who and where the user talks to most in Slack, so the names they say
     * are recognised without searching, and the ids used directly.
     *
     * @param  array{people: list<ChatPerson>, conversations: list<ChatConversation>}  $familiar
     */
    private static function familiarChats(array $familiar): string
    {
        if ($familiar['people'] === [] && $familiar['conversations'] === []) {
            return '';
        }

        $people = implode('; ', array_map(
            fn (ChatPerson $person): string => $person->name.($person->title ? " ({$person->title})" : '')." [{$person->ref}]",
            $familiar['people'],
        ));

        $conversations = implode('; ', array_map(
            fn (ChatConversation $conversation): string => "{$conversation->label()} [{$conversation->ref}]",
            $familiar['conversations'],
        ));

        return 'Con quién y dónde habla más en Slack últimamente, de más a menos (entre corchetes, su person_id o conversation_id). '
            .'Cuando nombre a alguien o algo, aunque sea solo por el nombre de pila o con otra pronunciación, piensa primero en estos y usa su id directamente, sin buscarlo. '
            .'Si nombra a otro, búscalo con tus herramientas.'
            .($people !== '' ? "\nPersonas: {$people}." : '')
            .($conversations !== '' ? "\nCanales y grupos: {$conversations}." : '');
    }

    /**
     * What the user is working on lately, built overnight, when they switched it
     * on and it is recent. Already written: starting a conversation costs nothing more.
     */
    private static function workContext(User $user): string
    {
        $context = $user->workContext;

        if ($context === null || ! $context->usable()) {
            return '';
        }

        return WorkDigest::fromArray($context->digest ?? ['summary' => $context->summary])->forAssistant($context->generated_at);
    }

    /**
     * The work archive and the agents copied from it, so the assistant can
     * hand a turn to one of them instead of reading GitHub.
     */
    private static function sense(User $user): string
    {
        $repository = $user->workRepository();
        $agents = $user->senseAgents()->orderBy('name')->get();

        if ($repository === null && $agents->isEmpty()) {
            return '';
        }

        $lines = [];

        if ($repository !== null) {
            $lines[] = "Archivo de trabajo en GitHub ({$repository->full_name}). Para ver qué hay: list_sense_files. Para leer un archivo: read_sense_file. Para crearlo o cambiarlo: propose_sense_file. Para borrarlo: propose_sense_delete. Nada se escribe hasta que la persona diga que sí. Un documento con tipo obedece types/{tipo}.yml: where, name y required.";
        }

        if ($agents->isNotEmpty()) {
            $lines[] = 'Agentes del archivo. Si el tema encaja con uno, llama a focus_sense_agent con su slug antes de responder y sigue las instrucciones que devuelva, referencias incluidas. Con slug vacío vuelves al asistente general. Después de crear o cambiar un agente o una referencia, llama a sync_sense_agents.';

            foreach ($agents as $agent) {
                $lines[] = "- {$agent->slug}: {$agent->name}. {$agent->purpose} Úsalo cuando: {$agent->cue}";
            }
        }

        return implode("\n", $lines);
    }
}
