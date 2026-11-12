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
use Illuminate\Support\Str;

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
            return $this->touch($user, $existing->person, $party, $self, $source);
        }

        if ($email !== null) {
            $byEmail = CommsIdentity::query()
                ->whereBelongsTo($user)
                ->where('email', $email)
                ->first();

            if ($byEmail !== null) {
                $this->attach($user, $byEmail->person, $source, $party, $email);
                $person = $this->touch($user, $byEmail->person, $party, $self, $source);

                if ($audit) {
                    $this->record($user, $source, $party, $person);
                }

                return $person;
            }
        }

        $relation = $this->defaultRelation($user, $source, $party, $self);
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

    private function touch(User $user, CommsPerson $person, Party $party, bool $self, Source $source): CommsPerson
    {
        if ($self && $person->relation === Relation::Unknown) {
            $person->relation = Relation::Self;
            $person->importance_weight = Relation::Self->weight();
        }

        if (! $self && $person->relation === Relation::Unknown) {
            $inferred = $this->defaultRelation($user, $source, $party, false);

            if ($inferred !== Relation::Unknown) {
                $person->relation = $inferred;
                $person->importance_weight = $inferred->weight();
            }
        }

        if ($party->bot && ! $person->is_bot) {
            $person->is_bot = true;
        }

        if ($person->isDirty()) {
            $person->save();
        }

        return $person;
    }

    /**
     * Teammates start as collaborators so importance is not stuck at unknown=5.
     * Personas can still override to boss, client, and the rest.
     */
    private function defaultRelation(User $user, Source $source, Party $party, bool $self): Relation
    {
        if ($self) {
            return Relation::Self;
        }

        if ($party->bot) {
            return Relation::Other;
        }

        if ($this->sharesWorkDomain($user, $party->email)) {
            return Relation::Collaborator;
        }

        // Same Slack workspace is the working team until Personas says otherwise.
        if ($source === Source::Slack) {
            return Relation::Collaborator;
        }

        return Relation::Unknown;
    }

    private function sharesWorkDomain(User $user, ?string $email): bool
    {
        if (! filled($email) || ! str_contains($email, '@')) {
            return false;
        }

        $domain = Str::lower(Str::after($email, '@'));

        if ($domain === '' || $this->isPublicMailDomain($domain)) {
            return false;
        }

        $user->loadMissing('connectedAccounts');

        foreach ($user->connectedAccounts as $account) {
            if (! filled($account->email) || ! str_contains((string) $account->email, '@')) {
                continue;
            }

            $accountDomain = Str::lower(Str::after((string) $account->email, '@'));

            if ($accountDomain !== '' && ! $this->isPublicMailDomain($accountDomain) && $accountDomain === $domain) {
                return true;
            }
        }

        return false;
    }

    private function isPublicMailDomain(string $domain): bool
    {
        return in_array($domain, [
            'gmail.com',
            'googlemail.com',
            'hotmail.com',
            'outlook.com',
            'live.com',
            'yahoo.com',
            'icloud.com',
            'me.com',
            'mac.com',
            'proton.me',
            'protonmail.com',
        ], true);
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
