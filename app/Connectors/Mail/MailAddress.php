<?php

namespace App\Connectors\Mail;

final readonly class MailAddress
{
    public function __construct(
        public ?string $name,
        public ?string $email,
    ) {}

    /**
     * Parse a header such as `"Ruiz, Marta" <marta@example.com>, pablo@example.com`.
     *
     * @return list<self>
     */
    public static function parseList(?string $header): array
    {
        if (blank($header)) {
            return [];
        }

        preg_match_all('/\s*(?:"([^"]*)"|([^,<"]*?))\s*<([^>]+)>|([^\s,<>"]+@[^\s,<>"]+)/u', $header, $matches, PREG_SET_ORDER);

        return array_map(fn (array $match): self => isset($match[4]) && $match[4] !== ''
            ? new self(null, $match[4])
            : new self(trim(($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '')) ?: null, trim($match[3])), $matches);
    }

    public static function parse(?string $header): ?self
    {
        return self::parseList($header)[0] ?? null;
    }

    public function label(): string
    {
        return $this->name ?? $this->email ?? '';
    }

    /**
     * @return array{name: ?string, email: ?string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'email' => $this->email];
    }

    /**
     * "Marta y Pablo", "Marta, Pablo y 2 más": first names, for labels.
     *
     * @param  list<self>  $addresses
     */
    public static function names(array $addresses): string
    {
        $names = array_map(fn (self $address): string => $address->name ? (string) strtok($address->name, ' ') : (string) $address->email, $addresses);

        return count($names) > 2 ? implode(', ', array_slice($names, 0, 2)).' y '.(count($names) - 2).' más' : implode(' y ', $names);
    }
}
