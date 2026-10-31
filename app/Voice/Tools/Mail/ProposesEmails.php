<?php

namespace App\Voice\Tools\Mail;

use App\Actions\Actions;
use App\Connectors\Mail\MailAddress;
use App\Connectors\Mail\MailMessage;
use App\Connectors\Mail\OutgoingEmail;
use App\Models\User;
use App\Voice\Tools\Canvas;
use App\Voice\Tools\Docs\ProposesChanges;
use App\Voice\Tools\ToolResult;

/**
 * Shared steps for proposing an email: resolve people, show the draft and
 * wait for the person's "sí". Nothing is sent from here.
 */
trait ProposesEmails
{
    use ProposesChanges;

    /**
     * @param  array<string, mixed>  $extra  More for the action's payload (the alert it answers).
     */
    private function propose(Actions $actions, User $user, OutgoingEmail $email, string $message, ?MailMessage $original = null, ?string $timezone = null, array $extra = []): ToolResult
    {
        $label = match ($email->mode) {
            'reply' => 'Responder a '.MailAddress::names($email->to),
            'reply_all' => 'Responder a todos',
            'forward' => 'Reenviar a '.MailAddress::names($email->to),
            default => 'Email a '.MailAddress::names($email->to),
        };

        $action = $actions->propose($user, 'mail.send', $label, ['email' => $email->toArray(), ...$extra]);
        $people = fn (array $addresses): array => array_map(fn (MailAddress $address): array => $address->toArray(), $addresses);

        return new ToolResult(
            output: $this->proposalOutput($action, [
                'mode' => $email->mode,
                'from' => $email->from,
                'to' => array_map(fn (MailAddress $address): string => (string) $address->email, $email->to),
                'cc' => array_map(fn (MailAddress $address): string => (string) $address->email, $email->cc) ?: null,
                'subject' => $email->subject,
                'attachments' => array_column($email->attachments, 'name') ?: null,
            ]),
            canvas: new Canvas('mail.draft', [
                'action' => $action->toState(),
                'mode' => $email->mode,
                'from' => $email->from,
                'to' => $people($email->to),
                'cc' => $people($email->cc),
                'subject' => $email->subject,
                'message' => rtrim($message),
                'trail' => trim(mb_substr($email->body, mb_strlen(rtrim($message)))),
                'attachments' => array_map(fn (array $attachment): array => ['name' => $attachment['name'], 'size' => $attachment['size']], $email->attachments),
            ]),
            beside: $original !== null && $timezone !== null ? (new EmailCanvas)->open($original, $timezone) : null,
        );
    }
}
