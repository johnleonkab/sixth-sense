<?php

namespace App\Comms\Backfill;

use App\Models\User;

/**
 * The five backfill passes, always in this order. Later tickets fill each one.
 */
class Passes
{
    /**
     * @var list<string>
     */
    public const ORDER = ['identity', 'labels', 'topics', 'patterns', 'sweep'];

    public function __construct(private IdentityPass $identity) {}

    /**
     * @return list<string>|null Null when the request names a pass that does not exist.
     */
    public function select(string $requested): ?array
    {
        $requested = trim($requested);

        if ($requested === '' || $requested === 'all') {
            return self::ORDER;
        }

        $names = array_values(array_filter(
            array_map(trim(...), explode(',', $requested)),
            fn (string $name): bool => $name !== '',
        ));

        foreach ($names as $name) {
            if (! in_array($name, self::ORDER, true)) {
                return null;
            }
        }

        return array_values(array_filter(
            self::ORDER,
            fn (string $name): bool => in_array($name, $names, true),
        ));
    }

    /**
     * @return array<string, int>
     */
    public function run(User $user, string $pass): array
    {
        if ($pass === 'identity') {
            return $this->identity->run($user);
        }

        return [];
    }
}
