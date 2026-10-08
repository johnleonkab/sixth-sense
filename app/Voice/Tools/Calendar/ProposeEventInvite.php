<?php

namespace App\Voice\Tools\Calendar;

use App\Connectors\Mail\MailAddress;
use App\Connectors\Mail\RecipientNotResolved;
use App\Models\User;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

class ProposeEventInvite implements VoiceTool
{
    use ProposesCalendarChanges;

    public function name(): string
    {
        return 'propose_event_invite';
    }

    public function description(): string
    {
        return 'Propone invitar a más personas a una reunión que organiza el usuario; recibirán la invitación. Se muestra como borrador y NO se aplica hasta que el usuario lo confirme. '
            .'Personas por nombre o email; si son ambiguas, pregunta.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => self::eventIdParameter(),
                'people' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'A quién invitar: nombres o emails.'],
            ],
            'required' => ['event_id', 'people'],
        ];
    }

    public function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'max:500'],
            'people' => ['required', 'array', 'min:1', 'max:20'],
            'people.*' => ['required', 'string', 'max:200'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $event = $this->changeableEvent($user, $arguments['event_id'], $context, mustOrganize: true);

        if ($event instanceof ToolResult) {
            return $event;
        }

        try {
            $people = $this->recipients->resolveAll($user, $arguments['people']);
        } catch (RecipientNotResolved $exception) {
            return ToolResult::error($exception->getMessage());
        }

        $invited = array_map(fn (array $attendee): string => strtolower((string) $attendee['email']), $event->attendees);
        $people = array_values(array_filter($people, fn (MailAddress $person): bool => ! in_array(strtolower((string) $person->email), $invited, true)));

        if ($people === []) {
            return ToolResult::error('Esas personas ya están invitadas.');
        }

        return $this->proposeChange(
            $user,
            'invite',
            'Invitar a '.MailAddress::names($people).' a «'.$event->title.'»',
            ['ref' => $event->ref, 'people' => array_map(fn (MailAddress $person): array => $person->toArray(), $people)],
            $event,
            ['new_people' => array_map(fn (MailAddress $person): array => $person->toArray(), $people)],
        );
    }
}
