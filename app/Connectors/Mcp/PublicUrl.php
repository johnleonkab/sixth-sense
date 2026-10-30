<?php

namespace App\Connectors\Mcp;

/**
 * Only public https addresses may be called: a URL the person typed must
 * never reach this server's own network (localhost, private ranges, cloud
 * metadata).
 */
class PublicUrl
{
    /**
     * Why the URL cannot be used, or null when it can.
     */
    public static function problem(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || blank($parts['host'] ?? null)) {
            return 'La URL tiene que empezar por https://.';
        }

        $host = trim($parts['host'], '[]');

        if (in_array(strtolower($host), ['localhost', 'localhost.localdomain'], true) || str_ends_with(strtolower($host), '.local') || str_ends_with(strtolower($host), '.internal')) {
            return 'Esa dirección no es pública.';
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);

        if ($addresses === []) {
            return 'No encuentro ese servidor.';
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return 'Esa dirección no es pública.';
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (! is_array($records)) {
            return [];
        }

        return array_values(array_filter(array_map(fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null, $records)));
    }
}
