<?php

namespace App\Voice\Tools\Context;

use App\Comms\Context\ContextWriter;
use App\Comms\Context\FactOrigin;
use App\Models\User;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;
use Illuminate\Support\Str;

/**
 * Stores one fact the person just said. It only changes what sixth-sense
 * knows, so it does not wait for a yes.
 */
class RememberFact implements VoiceTool
{
    public function __construct(private ContextWriter $context) {}

    public function name(): string
    {
        return 'remember';
    }

    public function description(): string
    {
        return 'Guarda un hecho que la persona acaba de decir sobre alguien o sobre su trabajo: la relación, el idioma, el horario o una regla. No envía nada fuera.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'subject' => ['type' => 'string', 'description' => 'De quién o de qué. "user" si es sobre la propia persona.'],
                'attribute' => ['type' => 'string', 'description' => 'relation, language, hours o rule.'],
                'value' => ['type' => 'string', 'description' => 'El hecho, en una frase.'],
            ],
            'required' => ['subject', 'attribute', 'value'],
        ];
    }

    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:120'],
            'attribute' => ['required', 'string', 'in:relation,language,hours,rule'],
            'value' => ['required', 'string', 'max:240'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $attribute = (string) $arguments['attribute'];
        $about = trim((string) $arguments['subject']);
        $self = in_array(Str::lower($about), ['user', 'yo', 'me', ''], true);
        $subject = match (true) {
            $self => 'user',
            str_contains($about, ':') => $about,
            default => $this->context->subject($about),
        };

        $this->context->record($user, FactOrigin::User, [[
            'op' => $attribute === 'rule' ? 'add' : 'set',
            'kind' => match (true) {
                $attribute === 'rule' => 'rule',
                $attribute === 'hours' && $self => 'preference',
                default => 'person',
            },
            'subject' => $subject,
            'attribute' => $attribute,
            'value' => array_filter([
                ($attribute === 'rule' ? 'text' : 'value') => trim((string) $arguments['value']),
                'name' => $self || str_contains($about, ':') ? null : $about,
            ]),
            'audiences' => [],
        ]]);

        return new ToolResult(['remembered' => true]);
    }
}
