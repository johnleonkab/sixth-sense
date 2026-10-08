<?php

namespace App\Connectors\Google;

use App\Connections\AccessTokens;
use App\Connections\Capability;
use App\Connectors\Mail\MailAddress;
use App\Connectors\Mail\MailSender;
use App\Connectors\Mail\Outbox;
use App\Connectors\Mail\OutgoingEmail;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends through the Gmail API, so the email appears in the user's Sent folder
 * and replies stay in their thread.
 *
 * @see https://developers.google.com/gmail/api/guides/sending
 */
class GmailOutbox implements Outbox
{
    private const API = 'https://gmail.googleapis.com/gmail/v1/users/me';

    /** Gmail refuses messages over 25 MB; leave room for encoding. */
    private const MAX_ATTACHMENTS_BYTES = 18_000_000;

    public function __construct(private AccessTokens $tokens) {}

    public function senders(User $user): array
    {
        return $user->accountsWith(Capability::GmailSend)
            ->map(fn (ConnectedAccount $account): MailSender => new MailSender($account->id, (string) $account->email))
            ->values()
            ->all();
    }

    public function senderFor(User $user, string $originalRef): ?MailSender
    {
        return collect($this->senders($user))->firstWhere('accountId', (int) strtok($originalRef, '.'));
    }

    public function send(User $user, OutgoingEmail $email): string
    {
        $account = $user->accountsWith(Capability::GmailSend)->firstWhere('id', $email->accountId)
            ?? throw new RuntimeException('Esta cuenta no tiene permiso para enviar emails. Actívalo en Configuración y vuelve a conectarla.');

        $token = $this->tokens->for($account);
        $message = $this->mime($email, $token);

        $response = Http::withToken($token)->acceptJson()->timeout(30)->post(self::API.'/messages/send', array_filter([
            'raw' => rtrim(strtr(base64_encode($message->toString()), '+/', '-_'), '='),
            'threadId' => $email->threadId,
        ]));

        if ($response->failed()) {
            throw new RuntimeException('Gmail no ha enviado el email: '.($response->json('error.message') ?? "HTTP {$response->status()}"));
        }

        return $account->id.'.'.$response->json('id');
    }

    public function urlFor(User $user, OutgoingEmail $email, string $sentRef): ?string
    {
        [, $id] = explode('.', $sentRef, 2);

        return 'https://mail.google.com/mail/?authuser='.urlencode($email->from).'#sent/'.$id;
    }

    private function mime(OutgoingEmail $email, string $token): Email
    {
        $address = fn (MailAddress $person): Address => new Address((string) $person->email, (string) $person->name);

        $message = (new Email)
            ->from($email->from)
            ->to(...array_map($address, $email->to))
            ->subject($email->subject)
            ->text($email->body);

        if ($email->cc !== []) {
            $message->cc(...array_map($address, $email->cc));
        }

        if ($email->inReplyTo !== null) {
            $message->getHeaders()->addTextHeader('In-Reply-To', $email->inReplyTo);
            $message->getHeaders()->addTextHeader('References', trim(($email->references ?? '').' '.$email->inReplyTo));
        }

        $this->attachOriginals($message, $email, $token);

        return $message;
    }

    /**
     * A forward carries the original's attachments, fetched at send time.
     */
    private function attachOriginals(Email $message, OutgoingEmail $email, string $token): void
    {
        if ($email->attachments === [] || $email->original === null) {
            return;
        }

        [, $messageId] = explode('.', $email->original, 2);
        $total = 0;

        foreach ($email->attachments as $attachment) {
            if (empty($attachment['id']) || ($total += $attachment['size']) > self::MAX_ATTACHMENTS_BYTES) {
                continue;
            }

            $data = Http::withToken($token)->acceptJson()->timeout(30)
                ->get(self::API.'/messages/'.rawurlencode($messageId).'/attachments/'.rawurlencode($attachment['id']))
                ->throw()
                ->json('data');

            $message->attach((string) base64_decode(strtr($data, '-_', '+/')), $attachment['name'], $attachment['mime_type']);
        }
    }
}
