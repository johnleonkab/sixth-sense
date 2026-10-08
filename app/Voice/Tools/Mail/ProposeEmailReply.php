<?php

namespace App\Voice\Tools\Mail;

use App\Actions\Actions;
use App\Connectors\Mail\EmailComposer;
use App\Connectors\Mail\Mailbox;
use App\Connectors\Mail\MailSender;
use App\Connectors\Mail\Outbox;
use App\Connectors\Mail\RecipientResolver;
use App\Models\User;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

class ProposeEmailReply implements VoiceTool
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
        return 'propose_email_reply';
    }

    public function description(): string
    {
        return 'Prepara una RESPUESTA a un email (email_id de una búsqueda o de un aviso) y la muestra como borrador, en el mismo hilo. NO la envía: el usuario tiene que confirmarlo. '
            .'reply_all = false responde solo a quien lo envió ("contéstale a Marta"); true responde también a todos los que estaban en el email ("responde a todos"). '
            .'Escribe solo el texto nuevo, en primera persona, breve; el email original se cita solo.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'email_id' => ['type' => 'string', 'description' => 'email_id del email al que se responde.'],
                'body' => ['type' => 'string', 'description' => 'Texto de la respuesta.'],
                'reply_all' => ['type' => 'boolean', 'description' => 'Responder a todos. Por defecto, solo al remitente.'],
            ],
            'required' => ['email_id', 'body'],
        ];
    }

    public function rules(): array
    {
        return [
            'email_id' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
            'reply_all' => ['nullable', 'boolean'],
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

        $email = $this->composer->reply(
            $original,
            $sender,
            $arguments['body'],
            (bool) ($arguments['reply_all'] ?? false),
            [...array_map(fn (MailSender $sender): string => $sender->email, $this->outbox->senders($user)), ...$user->connectedAccounts()->pluck('email')->all()],
        );

        if ($email->to === []) {
            return ToolResult::error('No hay a quién responder en ese email.');
        }

        return $this->propose($this->actions, $user, $email, $arguments['body']);
    }
}
