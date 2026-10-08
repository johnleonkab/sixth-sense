<?php

namespace App\Connectors\Mail;

/**
 * Builds replies and forwards from the original email: who receives it, the
 * subject, the quoted text and the threading headers.
 */
class EmailComposer
{
    /**
     * @param  list<string>  $ownAddresses  The user's addresses, never replied to.
     */
    public function reply(MailMessage $original, MailSender $sender, string $body, bool $all, array $ownAddresses): OutgoingEmail
    {
        $own = array_map('strtolower', $ownAddresses);
        $notOwn = fn (MailAddress $address): bool => $address->email !== null && ! in_array(strtolower($address->email), $own, true);
        $unique = fn (array $addresses): array => array_values(collect($addresses)->filter($notOwn)->unique(fn (MailAddress $address): string => strtolower((string) $address->email))->all());

        // Replying to an email the user sent goes back to its recipients.
        $sentByUser = $original->from !== null && ! $notOwn($original->from);
        $to = $unique($sentByUser ? $original->to : ($original->replyTo ?: array_filter([$original->from])));
        $cc = $all ? $unique([...($sentByUser ? [] : $original->to), ...$original->cc]) : [];
        $cc = array_values(array_filter($cc, fn (MailAddress $address): bool => ! collect($to)->contains(fn (MailAddress $recipient): bool => strcasecmp((string) $recipient->email, (string) $address->email) === 0)));

        return new OutgoingEmail(
            mode: $all ? 'reply_all' : 'reply',
            accountId: $sender->accountId,
            from: $sender->email,
            to: $to,
            cc: $cc,
            subject: self::prefixed('Re:', $original->subject),
            body: rtrim($body)."\n\n".$this->quote($original),
            original: $original->ref,
            threadId: $original->threadId,
            inReplyTo: $original->messageId,
            references: $original->references,
        );
    }

    /**
     * @param  list<MailAddress>  $to
     */
    public function forward(MailMessage $original, MailSender $sender, array $to, string $note): OutgoingEmail
    {
        $header = implode("\n", array_filter([
            '---------- Mensaje reenviado ----------',
            'De: '.self::person($original->from),
            'Fecha: '.$original->date->locale('es')->isoFormat('dddd, D [de] MMMM [de] YYYY, H:mm'),
            'Asunto: '.$original->subject,
            'Para: '.implode(', ', array_map(self::person(...), $original->to)),
            $original->cc ? 'CC: '.implode(', ', array_map(self::person(...), $original->cc)) : null,
        ]));

        return new OutgoingEmail(
            mode: 'forward',
            accountId: $sender->accountId,
            from: $sender->email,
            to: $to,
            cc: [],
            subject: self::prefixed('Fwd:', $original->subject),
            body: trim(rtrim($note)."\n\n".$header."\n\n".($original->body ?? $original->snippet)),
            original: $original->ref,
            attachments: $original->attachments,
        );
    }

    private function quote(MailMessage $original): string
    {
        $intro = 'El '.$original->date->locale('es')->isoFormat('D [de] MMMM [de] YYYY, H:mm').', '.self::person($original->from).' escribió:';
        $lines = array_map(fn (string $line): string => '> '.$line, explode("\n", trim($original->body ?? $original->snippet)));

        return $intro."\n".implode("\n", $lines);
    }

    private static function prefixed(string $prefix, string $subject): string
    {
        return preg_match('/^(re|fwd?|rv|res|enc):/i', trim($subject)) && strcasecmp(substr(trim($subject), 0, strlen($prefix)), $prefix) === 0
            ? trim($subject)
            : $prefix.' '.trim($subject);
    }

    private static function person(?MailAddress $address): string
    {
        return $address === null ? '' : trim(($address->name ? $address->name.' ' : '').'<'.$address->email.'>');
    }
}
