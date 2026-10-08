<?php

namespace App\Voice\Tools\Mail;

use App\Actions\Actions;
use App\Connectors\Mail\EmailComposer;
use App\Connectors\Mail\Mailbox;
use App\Connectors\Mail\Outbox;
use App\Connectors\Mail\RecipientNotResolved;
use App\Connectors\Mail\RecipientResolver;
use App\Models\User;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

class ProposeEmailForward implements VoiceTool
{
    use ProposesEmails;

    public function __construct(
        private Mailbox $mailbox,
        private Outbox $outbox,
        private EmailComposer $composer,
        private RecipientResolver $recipients,
        private Actions $actions,
    ) {}

    public function name(): string
    {
        return 'propose_email_forward';
    }

    public function description(): string
    {
        return 'Prepara el REENVÍO de un email (email_id) a otras personas, con sus adjuntos, y lo muestra como borrador. NO lo envía: el usuario tiene que confirmarlo. '
            .'Destinatarios por nombre o email. note es un texto opcional que va encima del email reenviado ("Pablo, mira esto").';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'email_id' => ['type' => 'string', 'description' => 'email_id del email a reenviar.'],
                'to' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'A quién: nombres o emails.'],
                'note' => ['type' => 'string', 'description' => 'Texto opcional encima del reenvío.'],
            ],
            'required' => ['email_id', 'to'],
        ];
    }

    public function rules(): array
    {
        return [
            'email_id' => ['required', 'string', 'max:200'],
            'to' => ['required', 'array', 'min:1', 'max:20'],
            'to.*' => ['required', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:10000'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $original = $this->mailbox->message($user, $arguments['email_id']);

        if ($original === null) {
            return ToolResult::error('No encuentro ese email. Búscalo de nuevo para obtener su email_id.');
        }

        $sender = $this->outbox->senderFor($user, $original->ref);

        if ($sender === null) {
            return ToolResult::error("La cuenta {$original->account} no tiene permiso para enviar emails. El usuario puede activarlo en Configuración (Conexiones) y volver a conectar la cuenta.");
        }

        try {
            $to = $this->recipients->resolveAll($user, $arguments['to']);
        } catch (RecipientNotResolved $exception) {
            return ToolResult::error($exception->getMessage());
        }

        $note = $arguments['note'] ?? '';

        return $this->propose($this->actions, $user, $this->composer->forward($original, $sender, $to, $note), $note);
    }
}
