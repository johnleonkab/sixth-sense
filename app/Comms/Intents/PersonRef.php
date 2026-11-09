<?php

namespace App\Comms\Intents;

use App\Comms\People\Relation;
use App\Models\Comms\CommsIdentity;
use App\Models\Comms\CommsPerson;
use App\Models\User;

/**
 * Stable person keys. Database ids change when replay rebuilds the rows.
 */
class PersonRef
{
    public function key(CommsPerson $person): string
    {
        if ($person->relation === Relation::Self) {
            return 'self';
        }

        $identity = $person->relationLoaded('identities')
            ? $person->identities->first()
            : $person->identities()->first();

        if ($identity === null) {
            return 'person:'.$person->id;
        }

        return $identity->source->value.':'.$identity->external_id;
    }

    public function find(User $user, string $ref): ?CommsPerson
    {
        if ($ref === 'self' || $ref === 'person:user') {
            return CommsPerson::query()->whereBelongsTo($user)->where('relation', Relation::Self)->first();
        }

        [$source, $externalId] = array_pad(explode(':', $ref, 2), 2, null);

        if (! is_string($source) || ! is_string($externalId) || $externalId === '') {
            return null;
        }

        return CommsIdentity::query()
            ->whereBelongsTo($user)
            ->where('source', $source)
            ->where('external_id', $externalId)
            ->first()
            ?->person;
    }

    /**
     * @param  list<mixed>  $refs
     * @return array{0: list<CommsPerson>, 1: bool}
     */
    public function resolveAll(User $user, array $refs): array
    {
        $people = [];

        foreach ($refs as $ref) {
            if (! is_string($ref)) {
                return [[], true];
            }

            $person = $this->find($user, $ref);

            if ($person === null) {
                return [[], true];
            }

            $people[] = $person;
        }

        return [$people, false];
    }
}
