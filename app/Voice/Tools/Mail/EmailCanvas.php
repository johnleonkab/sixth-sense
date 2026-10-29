<?php

namespace App\Voice\Tools\Mail;

use App\Connectors\Docs\DocumentLinks;
use App\Connectors\Mail\MailAddress;
use App\Connectors\Mail\MailMessage;
use App\Connectors\Mail\MailPattern;
use App\Voice\Tools\Canvas;
use Illuminate\Support\Str;

/**
 * The email canvas, shared by opening a message and by putting it beside a draft.
 */
final class EmailCanvas
{
    private const MAX_BODY = 30000;

    public function open(MailMessage $message, string $timezone, ?MailPattern $highlight = null): Canvas
    {
        $body = Str::limit($message->body ?? $message->snippet, self::MAX_BODY);
        $documents = DocumentLinks::in($message->body);
        $people = fn (array $addresses): array => array_map(fn (MailAddress $address): array => $address->toArray(), $addresses);

        return new Canvas('mail.message', [
            'ref' => $message->ref,
            'subject' => $message->subject,
            'from' => $message->from?->toArray(),
            'to' => $people($message->to),
            'cc' => $people($message->cc),
            'date' => $message->date->setTimezone($timezone)->toIso8601String(),
            'account' => $message->account,
            'body' => $highlight ? $highlight->segments($body) : [['text' => $body, 'match' => false]],
            'attachments' => $message->attachments,
            'documents' => array_map(fn (array $link): array => ['title' => null, 'url' => $link['url']], $documents),
            'url' => $message->url,
        ]);
    }
}
