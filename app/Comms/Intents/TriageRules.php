<?php

namespace App\Comms\Intents;

use App\Comms\People\Relation;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\User;

/**
 * Rules run before the triage model. The first match wins.
 */
class TriageRules
{
    public function __construct(private PersonRef $people) {}

    public function classify(User $user, CommsMessage $message, CommsEvent $event): ?TriageResult
    {
        $author = $message->author;
        $normalized = $event->normalized ?? [];

        if ($author?->relation === Relation::Self && $this->onlyRecipientIsSelf($user, $message)) {
            return new TriageResult('noise');
        }

        $allowed = $this->inboxRule($author, $normalized);

        if ($author?->is_bot && $allowed === null) {
            return new TriageResult('noise');
        }

        if ($allowed !== null) {
            return new TriageResult($allowed);
        }

        $headers = array_change_key_case(is_array($normalized['headers'] ?? null) ? $normalized['headers'] : []);

        if (filled($headers['list-unsubscribe'] ?? null) || strtolower((string) ($headers['precedence'] ?? '')) === 'bulk') {
            return new TriageResult('noise');
        }

        if ($this->copiedOnly($user, $message, $normalized)) {
            return new TriageResult('cc_only');
        }

        return null;
    }

    /**
     * An allow-listed sender and subject, read from the message itself.
     */
    public function inboxLabel(CommsMessage $message): ?string
    {
        $line = preg_split('/\R/u', $message->text);
        $subject = is_array($line) ? (string) ($line[0] ?? '') : '';

        return $this->inboxRule($message->author, ['subject' => $subject]);
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function inboxRule(?CommsPerson $author, array $normalized): ?string
    {
        $from = strtolower((string) ($author?->identities()->value('email') ?? ''));
        $subject = strtolower((string) ($normalized['subject'] ?? strtok($normalized['text'] ?? '', "\n")));
        $rules = config('comms.inbox_rules', []);

        if (! is_array($rules)) {
            return null;
        }

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $fromFragment = strtolower((string) ($rule['from'] ?? ''));
            $subjectFragment = strtolower((string) ($rule['subject'] ?? ''));

            if ($fromFragment !== '' && str_contains($from, $fromFragment) && str_contains($subject, $subjectFragment)) {
                $label = (string) ($rule['label'] ?? 'delivery');

                return in_array($label, ['delivery', 'ask'], true) ? $label : 'delivery';
            }
        }

        return null;
    }

    private function onlyRecipientIsSelf(User $user, CommsMessage $message): bool
    {
        $self = CommsPerson::query()->whereBelongsTo($user)->where('relation', Relation::Self)->first();
        $ids = $message->recipient_person_ids ?? [];

        return $self !== null && $ids !== [] && array_values(array_unique($ids)) === [$self->id];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function copiedOnly(User $user, CommsMessage $message, array $normalized): bool
    {
        $self = CommsPerson::query()->whereBelongsTo($user)->where('relation', Relation::Self)->with('identities')->first();

        if ($self === null) {
            return false;
        }

        $emails = $self->identities->pluck('email')->filter()->map(fn (string $email): string => strtolower($email))->all();
        $cc = collect($normalized['cc'] ?? [])
            ->map(fn (mixed $party): string => strtolower((string) (is_array($party) ? ($party['email'] ?? '') : '')))
            ->filter()
            ->all();
        $to = $self->identities->isEmpty() ? [] : collect($message->recipient_person_ids ?? [])->all();
        $userIsAddressed = CommsPerson::query()->whereIn('id', $to)->whereKey($self->id)->exists();
        $userIsCopied = array_intersect($emails, $cc) !== [];

        if (! $userIsCopied || $userIsAddressed) {
            return false;
        }

        $body = mb_strtolower($message->text);
        $names = array_filter([mb_strtolower($self->display_name), ...$self->identities->pluck('handle')->filter()->map(fn (string $handle): string => mb_strtolower($handle))->all()]);

        foreach ($names as $name) {
            if ($name !== '' && str_contains($body, $name)) {
                return false;
            }
        }

        return true;
    }
}
