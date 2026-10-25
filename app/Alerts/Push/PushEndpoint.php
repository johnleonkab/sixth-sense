<?php

namespace App\Alerts\Push;

/**
 * The browser's push address has to be a known push service, so the server
 * never posts a notification to an arbitrary URL.
 */
final class PushEndpoint
{
    /** @var list<string> */
    private const HOSTS = [
        'fcm.googleapis.com',
        'push.services.mozilla.com',
        'push.apple.com',
        'notify.windows.com',
    ];

    public static function allowed(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        $host = is_string($parts['host'] ?? null) ? strtolower($parts['host']) : '';

        if (($parts['scheme'] ?? null) !== 'https' || $host === '') {
            return false;
        }

        foreach (self::HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }
}
