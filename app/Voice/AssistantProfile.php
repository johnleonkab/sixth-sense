<?php

namespace App\Voice;

use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Connectors\Chat\ChatConversation;
use App\Connectors\Chat\ChatPerson;
use App\Connectors\Chat\Chats;
use App\Connectors\Issues\IssueTracker;
use App\Connectors\Jira\JiraRules;
use App\Knowledge\Gaps\GapStatus;
use App\Models\KnowledgeGap;
use App\Models\User;
use App\Voice\OpenUi\OpenUi;
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
                self::knowledge($user),
                OpenUi::prompt(),
                'Si el encargo necesita más de una herramienta (buscar hueco y contestar, dejar varias historias, preguntar a un agente), la única llamada es open_scene. No encadenes las herramientas de ese encargo.',
                'Para encontrar un documento, una hoja, unas diapositivas o una página, la única llamada es find_files, con uno o varios términos. No busques primero en Docs y luego en Notion.',
                $issues ? self::jiraRules($issues->rules($user)) : '',
            ]))),
            tools: $toolbox?->declarations() ?? [],
        );
    }

    /**
     * The local copy of the archive, Jira and Notion, so questions about the
     * work are answered from it instead of from memory.
     */
    private static function knowledge(User $user): string
    {
        $lines = [];

        if ($user->knowledgeDocuments()->exists()) {
            $lines[] = 'Antes de responder sobre un proyecto, una decisión, un proceso, una reunión, una persona o cualquier contexto del trabajo, llama a search_knowledge: busca a la vez en el archivo de trabajo, Jira, Notion, las notas y transcripciones de reuniones y lo que el usuario te ha explicado. Responde con lo que encuentres y di de dónde sale. Si no hay nada, dilo; no lo inventes.';
        }

        $answers = $user->knowledgeGaps()->where('status', GapStatus::Answered)->latest('answered_at')->limit(30)->get();

        if ($answers->isNotEmpty()) {
            $lines[] = "Lo que el usuario te ha explicado cuando preguntaste:\n".$answers->map(fn (KnowledgeGap $gap): string => '- '.$gap->memory())->implode("\n");
        }

        $open = $user->inboxItems()->where('source', AlertSource::Questions)->where('status', AlertStatus::Pending)->count();

        if ($open > 0) {
            $lines[] = "Tienes {$open} ".($open === 1 ? 'pregunta' : 'preguntas').' sin responder (avisos de tipo Pregunta). Ofrécelas cuando el usuario tenga un momento, de una en una, y guarda su respuesta con answer_question.';
        }

        return implode("\n", $lines);
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
     * The work archive as Claude Code would load it: CLAUDE.md in full, and
     * only the name and description of each agent and skill. Their bodies
     * load when one is used.
     */
    private static function sense(User $user): string
    {
        $repository = $user->workRepository();
        $agents = $user->senseAgents()->orderBy('name')->get();
        $skills = $user->senseSkills()->where('model_invocable', true)->orderBy('name')->get();

        if ($repository === null && $agents->isEmpty() && $skills->isEmpty()) {
            return '';
        }

        $lines = [];

        if ($repository !== null) {
            $lines[] = "Archivo de trabajo en GitHub ({$repository->full_name}). Para ver qué hay: list_sense_files. Para leer un archivo: read_sense_file. Para crearlo o cambiarlo: propose_sense_file. Para borrarlo: propose_sense_delete. Nada se escribe hasta que la persona diga que sí. Un documento con tipo obedece types/{tipo}.yml: where, name y required.";

            if (filled($repository->memory)) {
                $lines[] = "CLAUDE.md del archivo:\n".mb_substr($repository->memory, 0, 6000);
            }
        }

        if ($agents->isNotEmpty()) {
            $lines[] = 'Agentes del archivo. Si el tema encaja con la description de uno, llama a focus_sense_agent con su name antes de responder y sigue las instrucciones que devuelva. Con name vacío vuelves al asistente general. Después de crear o cambiar un agente, una skill o CLAUDE.md, llama a sync_sense_agents.';

            foreach ($agents as $agent) {
                $lines[] = "- {$agent->name}: {$agent->description}";
            }
        }

        if ($skills->isNotEmpty()) {
            $lines[] = 'Skills del archivo. Si el tema encaja con la description de una, llama a use_sense_skill con su name y sigue lo que devuelva.';

            foreach ($skills as $skill) {
                $lines[] = "- {$skill->name}: {$skill->description}";
            }
        }

        return implode("\n", $lines);
    }
}
