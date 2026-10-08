<?php

namespace App\Voice;

use App\Connectors\Chat\ChatConversation;
use App\Connectors\Chat\ChatPerson;
use App\Connectors\Chat\Chats;
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
    public static function for(User $user, CarbonImmutable $now, ?Toolbox $toolbox = null, ?Chats $chats = null): self
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
            ]))),
            tools: $toolbox?->declarations() ?? [],
        );
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
}
