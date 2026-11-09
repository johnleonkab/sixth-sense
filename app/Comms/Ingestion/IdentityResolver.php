<?php

namespace App\Comms\Ingestion;

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Messages\Party;
use App\Comms\People\Relation;
use App\Connections\Provider;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIdentity;
use App\Models\Comms\CommsPerson;
use App\Models\User;

/**
 * Resolves a party to one person. Exact source id wins, then email.
 * A display name never merges two people. The result is appended as
 * person.identified so replay can rebuild the same rows.
 */
class IdentityResolver
{
    public function __construct(private EventLog $log) {}

    /**
     * The user's own person, from the accounts they have connected.
     */
    public function ensureSelf(User $user): void
    {
        foreach ($user->connectedAccounts as $account) {
            if ($account->provider === Provider::Google && filled($account->email)) {
                $email = strtolower($account->email);

                $this->resolve($user, Source::Gmail, new Party($email, $email, $account->name ?: $email), self: true, audit: true);
            }

            if ($account->provider === Provider::Slack) {
                $slackUserId = $account->metadata['user_id'] ?? null;

                if (is_string($slackUserId) && $slackUserId !== '') {
                    $email = filled($account->email) ? strtolower($account->email) : null;

                    $this->resolve(
                        $user,
                        Source::Slack,
                        new Party($slackUserId, $email, $account->name ?: $slackUserId),
                        self: true,
                        audit: true,
                    );
                }
            }
        }
    }

    public function resolve(User $user, Source $source, Party $party, bool $self = false, bool $audit = false): CommsPerson
    {
        $email = filled($party->email) ? strtolower($party->email) : null;
        $existing = CommsIdentity::query()
            ->whereBelongsTo($user)
            ->where('source', $source)
            ->where('external_id', $party->externalId)
            ->first();

        if ($existing !== null) {
            return $this->touch($existing->person, $party, $self);
        }

        if ($email !== null) {
            $byEmail = CommsIdentity::query()
                ->whereBelongsTo($user)
                ->where('email', $email)
                ->first();

            if ($byEmail !== null) {
                $this->attach($user, $byEmail->person, $source, $party, $email);
                $person = $this->touch($byEmail->person, $party, $self);

                if ($audit) {
                    $this->record($user, $source, $party, $person);
                }

                return $person;
            }
        }

        $relation = $self ? Relation::Self : Relation::Unknown;
        $person = CommsPerson::query()->create([
            'user_id' => $user->id,
            'display_name' => $party->displayName,
            'relation' => $relation,
            'importance_weight' => $relation->weight(),
            'is_bot' => $party->bot,
        ]);

        $this->attach($user, $person, $source, $party, $email);

        if ($audit) {
            $this->record($user, $source, $party, $person);
        }

        return $person;
    }

    /**
     * Replay of a person.identified row. The event is the audit, so this
     * does not append another one.
     */
    public function apply(CommsEvent $event): void
    {
        $normalized = $event->normalized ?? [];
        $party = $normalized['party'] ?? null;

        if (! is_array($party) || $event->user === null) {
            return;
        }

        $this->resolve(
            $event->user,
            Source::from((string) ($normalized['source'] ?? $event->source->value)),
            Party::fromArray($party),
            self: ($normalized['relation'] ?? null) === Relation::Self->value,
            audit: false,
        );
    }

    private function touch(CommsPerson $person, Party $party, bool $self): CommsPerson
    {
        if ($self && $person->relation === Relation::Unknown) {
            $person->relation = Relation::Self;
            $person->importance_weight = Relation::Self->weight();
        }

        if ($party->bot && ! $person->is_bot) {
            $person->is_bot = true;
        }

        if ($person->isDirty()) {
            $person->save();
        }

        return $person;
    }

    private function attach(User $user, CommsPerson $person, Source $source, Party $party, ?string $email): void
    {
        CommsIdentity::query()->create([
            'user_id' => $user->id,
            'person_id' => $person->id,
            'source' => $source,
            'external_id' => $party->externalId,
            'email' => $email,
            'handle' => $party->handle,
        ]);
    }

    private function record(User $user, Source $source, Party $party, CommsPerson $person): void
    {
        $event = $this->log->append(
            user: $user,
            type: EventType::PersonIdentified,
            source: $source,
            externalId: $source->value.':'.$party->externalId,
            payload: null,
            normalized: [
                'source' => $source->value,
                'party' => $party->toArray(),
                'relation' => $person->relation->value,
            ],
            causation: null,
            occurredAt: now(),
            dispatch: false,
        );

        if ($event->wasRecentlyCreated && $event->processed_at === null) {
            $event->processed_at = now();
            $event->save();
        }
    }
}
