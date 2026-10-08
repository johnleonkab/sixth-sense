<?php

namespace App\Voice\Tools\Mail;

use App\Actions\Actions;
use App\Connectors\Mail\MailSender;
use App\Connectors\Mail\Outbox;
use App\Connectors\Mail\OutgoingEmail;
use App\Connectors\Mail\RecipientNotResolved;
use App\Connectors\Mail\RecipientResolver;
use App\Models\User;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

class ProposeNewEmail implements VoiceTool
{
    use ProposesEmails;

    public function __construct(
        private Outbox $outbox,
        private RecipientResolver $recipients,
        private Actions $actions,
    ) {}

    public function name(): string
    {
        return 'propose_new_email';
    }

    public function description(): string
    {
        return 'Prepara un email NUEVO y lo muestra como borrador. NO lo envía: el usuario tiene que confirmarlo. '
            .'Para contestar a un email que ya existe usa propose_email_reply; para reenviarlo, propose_email_forward. '
            .'Destinatarios por nombre ("Marta") o email; si no se encuentran o son ambiguos, pregunta. '
            .'Escribe el cuerpo en primera persona, como lo escribiría el usuario, breve y con su saludo y despedida.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'to' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Destinatarios: nombres o emails.'],
                'cc' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'En copia: nombres o emails.'],
                'subject' => ['type' => 'string', 'description' => 'Asunto.'],
                'body' => ['type' => 'string', 'description' => 'Texto del email.'],
                'from_account' => ['type' => 'string', 'description' => 'Email de la cuenta desde la que enviar, si tiene varias y lo indica.'],
            ],
            'required' => ['to', 'subject', 'body'],
        ];
    }

    public function rules(): array
    {
        return [
            'to' => ['required', 'array', 'min:1', 'max:20'],
            'to.*' => ['required', 'string', 'max:200'],
            'cc' => ['nullable', 'array', 'max:20'],
            'cc.*' => ['string', 'max:200'],
            'subject' => ['required', 'string', 'max:300'],
            'body' => ['required', 'string', 'max:20000'],
            'from_account' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $senders = $this->outbox->senders($user);
        $sender = filled($arguments['from_account'] ?? null)
            ? collect($senders)->first(fn (MailSender $sender): bool => strcasecmp($sender->email, $arguments['from_account']) === 0)
            : ($senders[0] ?? null);

        if ($sender === null) {
            return ToolResult::error($senders === []
                ? 'Ninguna cuenta tiene permiso para enviar emails. El usuario puede activarlo en Configuración (Conexiones) y volver a conectar la cuenta.'
                : 'No puede enviar desde esa cuenta. Puede enviar desde: '.implode(', ', array_map(fn (MailSender $sender): string => $sender->email, $senders)).'.');
        }

        try {
            $to = $this->recipients->resolveAll($user, $arguments['to']);
            $cc = $this->recipients->resolveAll($user, $arguments['cc'] ?? []);
        } catch (RecipientNotResolved $exception) {
            return ToolResult::error($exception->getMessage());
        }

        $email = new OutgoingEmail('new', $sender->accountId, $sender->email, $to, $cc, $arguments['subject'], $arguments['body']);

        return $this->propose($this->actions, $user, $email, $arguments['body']);
    }
}
