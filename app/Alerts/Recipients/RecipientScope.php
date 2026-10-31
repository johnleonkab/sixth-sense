<?php

namespace App\Alerts\Recipients;

use App\Alerts\AlertSource;
use App\Connectors\Chat\ChatPerson;
use App\Connectors\Chat\Chats;
use App\Models\InboxItem;
use App\Models\User;
use Illuminate\Support\Str;
use Throwable;

/**
 * Who a reply goes to, seen from the person's organisation: someone inside
 * it, someone inside but outside the people they usually deal with (another
 * team), or someone outside it (another domain, a guest in Slack). What is
 * internal (issue keys, private links) is marked before it reaches anyone
 * but the first.
 */
class RecipientScope
{
    public const INTERNAL = 'internal';

    public const OTHER_TEAM = 'other_team';

    public const EXTERNAL = 'external';

    /** Issue keys and links to private tools. */
    private const INTERNAL_DATA = [
        'issue' => '/\b[A-Z][A-Z0-9]{1,9}-\d{1,6}\b/',
        'link' => '#https?://[^\s)]*(atlassian\.net|notion\.so|notion\.site|docs\.google\.com|drive\.google\.com|slack\.com/archives|app\.slack\.com)[^\s)]*#i',
    ];

    public function __construct(private Chats $chats) {}

    /**
     * @param  ?string  $email  The recipient's address, when the alert is an email.
     * @return array{scope: string, label: string}
     */
    public function of(InboxItem $item, ?string $email = null): array
    {
        $user = $item->user;
        $ours = $this->domains($user);

        try {
            $scope = match ($item->source) {
                AlertSource::Gmail => $email === null || $ours === [] || in_array(Str::lower(Str::after($email, '@')), $ours, true) ? self::INTERNAL : self::EXTERNAL,
                AlertSource::Slack => $this->slackScope($user, $item->actor_name, $ours),
                default => self::INTERNAL,
            };
        } catch (Throwable $exception) {
            report($exception);
            $scope = self::INTERNAL;
        }

        return ['scope' => $scope, 'label' => match ($scope) {
            self::EXTERNAL => "{$item->actor_name} es de fuera de tu organización",
            self::OTHER_TEAM => "{$item->actor_name} no suele trabajar contigo",
            default => "{$item->actor_name} es de tu organización",
        }];
    }

    /**
     * The internal data in a text: issue keys and private links.
     *
     * @return list<string>
     */
    public function internalData(string $text): array
    {
        $found = [];

        foreach (self::INTERNAL_DATA as $pattern) {
            preg_match_all($pattern, $text, $matches);
            array_push($found, ...$matches[0]);
        }

        return array_values(array_unique($found));
    }

    /**
     * What to keep with the proposal: whom it goes to and, when that is
     * outside the person's circle, the internal data the draft carries.
     *
     * @return ?array{scope: string, label: string, internal: list<string>}
     */
    public function check(InboxItem $item, ?string $text, ?string $email = null): ?array
    {
        $recipient = $this->of($item, $email);

        if ($recipient['scope'] === self::INTERNAL) {
            return null;
        }

        return [...$recipient, 'internal' => $this->internalData((string) $text)];
    }

    /**
     * @param  list<string>  $ours
     */
    private function slackScope(User $user, string $name, array $ours): string
    {
        $person = collect($this->chats->people($user, $name, 1))->first();

        if (! $person instanceof ChatPerson) {
            return self::INTERNAL;
        }

        if ($person->email !== null && $ours !== [] && ! in_array(Str::lower(Str::after($person->email, '@')), $ours, true)) {
            return self::EXTERNAL;
        }

        $familiar = array_map(fn (ChatPerson $known): string => $known->ref, $this->chats->familiar($user)['people']);

        return $familiar === [] || in_array($person->ref, $familiar, true) ? self::INTERNAL : self::OTHER_TEAM;
    }

    /**
     * The organisation's domains: those of the person's own accounts, and the
     * one most of their usual Slack colleagues share.
     *
     * @return list<string>
     */
    private function domains(User $user): array
    {
        $own = $user->connectedAccounts()->pluck('email')->filter()->map(fn (string $email): string => Str::lower(Str::after($email, '@')));
        $colleagues = collect($this->safely(fn (): array => $this->chats->familiar($user)['people']))
            ->map(fn (ChatPerson $person): ?string => $person->email ? Str::lower(Str::after($person->email, '@')) : null)
            ->filter()
            ->countBy()
            ->sortDesc()
            ->keys()
            ->take(1);

        return $own->merge($colleagues)->reject(fn (string $domain): bool => in_array($domain, ['gmail.com', 'googlemail.com', 'hotmail.com', 'outlook.com', 'yahoo.com', 'icloud.com'], true))->unique()->values()->all();
    }

    /**
     * @return list<mixed>
     */
    private function safely(callable $read): array
    {
        try {
            return $read();
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }
}
