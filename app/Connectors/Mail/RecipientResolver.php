<?php

namespace App\Connectors\Mail;

use App\Connectors\Chat\ChatPerson;
use App\Connectors\Chat\Chats;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Turns "Marta" into an address, from the people the user already exchanges
 * email with. Never guesses: several matches are returned for the person to choose.
 */
class RecipientResolver
{
    public function __construct(
        private Mailbox $mailbox,
        private Chats $chats,
    ) {}

    /**
     * @return list<MailAddress> One address when it is clear; several to choose from; none if unknown.
     */
    public function resolve(User $user, string $recipient): array
    {
        $recipient = trim($recipient);

        if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return [new MailAddress(null, $recipient)];
        }

        $known = [];
        $needle = Str::lower(Str::ascii($recipient));

        foreach ([['from' => $recipient], ['to' => $recipient]] as $filter) {
            foreach ($this->mailbox->search($user, new MailSearch(...$filter, limit: 10))->messages as $found) {
                foreach ([$found->message->from, ...$found->message->to, ...$found->message->cc] as $address) {
                    if ($address?->email && Str::contains(Str::lower(Str::ascii(($address->name ?? '').' '.$address->email)), $needle)) {
                        $known[strtolower($address->email)] ??= $address;
                    }
                }
            }
        }

        // Also the workspace directory, for colleagues never emailed before.
        foreach (rescue(fn (): array => $this->chats->people($user, $recipient, 10), [], report: false) as $person) {
            /** @var ChatPerson $person */
            if ($person->email) {
                $known[strtolower($person->email)] ??= new MailAddress($person->name, $person->email);
            }
        }

        return array_values($known);
    }

    /**
     * Resolve everyone or explain who could not be, so the assistant asks
     * instead of guessing.
     *
     * @param  list<string>  $recipients
     * @return list<MailAddress>
     *
     * @throws RecipientNotResolved
     */
    public function resolveAll(User $user, array $recipients): array
    {
        $resolved = [];

        foreach ($recipients as $recipient) {
            $matches = $this->resolve($user, $recipient);

            if ($matches === []) {
                throw new RecipientNotResolved("No encuentro la dirección de «{$recipient}» en su correo. Pídele el email.");
            }

            if (count($matches) > 1) {
                $options = implode(', ', array_map(fn (MailAddress $address): string => trim(($address->name ?? '').' <'.$address->email.'>'), array_slice($matches, 0, 5)));

                throw new RecipientNotResolved("Hay varias personas que encajan con «{$recipient}»: {$options}. Pregúntale cuál.");
            }

            $resolved[] = $matches[0];
        }

        return $resolved;
    }
}
