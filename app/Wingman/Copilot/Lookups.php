<?php

namespace App\Wingman\Copilot;

use App\Connectors\Calendar\CalendarWriter;
use App\Connectors\Calendar\NewEvent;
use App\Connectors\Chat\ChatPerson;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\NameMatch;
use App\Connectors\Chat\OutgoingChatMessage;
use App\Connectors\Issues\IssueTracker;
use App\Connectors\Mail\MailAddress;
use App\Connectors\Mail\Outbox;
use App\Connectors\Mail\OutgoingEmail;
use App\Connectors\Mail\RecipientResolver;
use App\Connectors\Wiki\Wiki;
use App\Models\WingmanMeeting;
use App\Scheduling\SlotFinder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Checks a finding with real data and turns it into what Wingman shows: the
 * slot that is free for everyone, how the issue stands, the page, the
 * message ready to send. Nothing found, nothing shown.
 */
class Lookups
{
    private const ISSUE_KEY = '/\b[A-Z][A-Z0-9]{1,9}-\d+\b/';

    public function __construct(
        private SlotFinder $slots,
        private CalendarWriter $calendar,
        private IssueTracker $issues,
        private Wiki $wiki,
        private Chats $chats,
        private Outbox $outbox,
        private RecipientResolver $recipients,
    ) {}

    /**
     * @return array{kind: string, title: string, body: ?string, why: string, quote: string, links: list<array{label: string, url: string}>, action: ?array<string, mixed>, importance: string, dedupe_key: string}|null
     */
    public function resolve(WingmanMeeting $meeting, Finding $finding): ?array
    {
        try {
            $card = match ($finding->kind) {
                'slot' => $this->slot($meeting, $finding),
                'issue' => $this->issue($meeting, $finding),
                'page' => $this->page($meeting, $finding),
                'message' => $this->message($meeting, $finding),
                default => null,
            };
        } catch (Throwable $exception) {
            // A service failing leaves this finding out, not the call.
            report($exception);

            return null;
        }

        return $card === null ? null : [
            'kind' => $finding->kind,
            'why' => 'Por: «'.Str::limit($finding->quote, 160).'»',
            'quote' => $finding->quote,
            'links' => [],
            'action' => null,
            'importance' => $finding->important ? 'high' : 'normal',
            ...$card,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function slot(WingmanMeeting $meeting, Finding $finding): array
    {
        $timezone = (string) config('agenda.timezone');
        $now = CarbonImmutable::now($timezone);
        $from = $this->date($finding->details['from_date'] ?? null, $timezone) ?? $now->addDay()->startOfDay();
        $from = $from->max($now);
        $to = ($this->date($finding->details['to_date'] ?? null, $timezone) ?? $from->addDays(4))->endOfDay();
        $to = $to->max($from->endOfDay());
        $minutes = max(15, min(120, (int) ($finding->details['duration_minutes'] ?? 30)));
        $people = $this->others($meeting);
        $title = Str::limit((string) ($finding->details['title'] ?? "Seguimiento: {$meeting->title}"), 120);
        $period = $from->locale('es')->isoFormat('D MMM').'–'.$to->locale('es')->isoFormat('D MMM');
        $dedupe = "slot:{$from->toDateString()}:{$to->toDateString()}";

        $slot = $this->slots->find($meeting->user, $from, $to, $minutes, array_values(array_filter(array_map(fn (MailAddress $person): ?string => $person->email, $people))));

        if ($slot === null) {
            return ['title' => "Sin hueco común del {$period}", 'body' => "No hay {$minutes} min libres para todos en esos días.", 'dedupe_key' => $dedupe];
        }

        [$start, $end] = $slot;
        $when = Str::ucfirst($start->locale('es')->isoFormat('dddd D [de] MMMM, HH:mm')).'–'.$end->format('H:i');
        $names = MailAddress::names($people);
        $account = $this->calendar->accounts($meeting->user)[0] ?? null;

        return [
            'title' => "Hueco para el seguimiento: {$when}",
            'body' => $people === [] ? 'Libre en tu calendario.' : "Libre para ti y para {$names}.",
            'dedupe_key' => $dedupe,
            'action' => $account === null ? null : [
                'type' => 'calendar.change',
                'label' => "Convocar «{$title}»",
                'preview' => "{$when}".($people === [] ? '' : " con {$names}"),
                'payload' => ['kind' => 'create', 'event' => (new NewEvent(
                    accountId: $account->accountId,
                    title: $title,
                    start: $start,
                    end: $end,
                    attendees: $people,
                    description: "Seguimiento de «{$meeting->title}». Propuesto por Wingman, de sixth-sense.",
                ))->toArray()],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function issue(WingmanMeeting $meeting, Finding $finding): ?array
    {
        $query = trim((string) ($finding->details['query'] ?? ''));

        if ($query === '') {
            return null;
        }

        $issue = null;

        foreach ($this->candidateKeys($meeting, $query) as $key) {
            if ($issue = $this->issues->issue($meeting->user, $key)) {
                break;
            }
        }

        $summary = $issue?->summary ?? ($this->issues->search($meeting->user, null, $query, 3)[0] ?? null);
        $blockedBy = collect($issue?->links ?? [])->where('relation', 'is blocked by')->pluck('key')->all();

        if ($summary === null) {
            return null;
        }

        return [
            'title' => "{$summary->key} · {$summary->summary}",
            'body' => implode(' · ', array_filter([
                "Está en {$summary->status}",
                $summary->assignee ? "asignado a {$summary->assignee}" : 'sin asignar',
                $blockedBy !== [] ? 'bloqueado por '.implode(', ', $blockedBy) : null,
            ])).'.',
            'links' => $summary->url ? [['label' => "Abrir {$summary->key}", 'url' => $summary->url]] : [],
            'dedupe_key' => "issue:{$summary->key}",
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function page(WingmanMeeting $meeting, Finding $finding): ?array
    {
        $words = preg_split('/\s+/u', trim((string) ($finding->details['query'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
        $page = null;

        // Said in passing, the name rarely matches the title word for word: shorter and shorter, down to two words.
        for ($length = count($words); $page === null && $length >= min(2, count($words)) && $length > 0; $length--) {
            $page = $this->wiki->search($meeting->user, implode(' ', array_slice($words, 0, $length)), 3)[0] ?? null;
        }

        if ($page === null) {
            return null;
        }

        return [
            'title' => $page->title,
            'body' => 'Página de Notion'.($page->parentTitle ? " en {$page->parentTitle}" : '').($page->editedAt ? ', editada '.$page->editedAt->locale('es')->diffForHumans() : '').'.',
            'links' => $page->url ? [['label' => 'Abrir en Notion', 'url' => $page->url]] : [],
            'dedupe_key' => "page:{$page->ref}",
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function message(WingmanMeeting $meeting, Finding $finding): ?array
    {
        $to = trim((string) ($finding->details['to'] ?? ''));
        $draft = trim((string) ($finding->details['draft'] ?? ''));

        $about = trim((string) ($finding->details['about'] ?? ''));

        if ($to === '') {
            return null;
        }

        // Promised but not written: kept as a reminder of what was said.
        if ($draft === '') {
            return [
                'title' => "Prometiste escribir a {$to}",
                'body' => $about !== '' ? "Sobre {$about}." : null,
                'dedupe_key' => 'message:'.Str::slug($to).':'.Str::limit(Str::slug($about), 60, ''),
            ];
        }
        $dedupe = 'message:'.Str::slug($to).':'.Str::limit(Str::slug($about), 60, '');
        $channel = $finding->details['channel'] ?? null;

        $person = $channel === 'email' ? null : $this->slackPerson($meeting, $to);

        if ($person !== null && $this->chats->canSend($meeting->user, $person->ref)) {
            return [
                'title' => "Mensaje para {$person->name}, listo",
                'body' => $draft,
                'dedupe_key' => $dedupe,
                'importance' => 'high',
                'action' => [
                    'type' => 'chat.send',
                    'label' => "Mensaje a {$person->name}",
                    'preview' => "Slack · {$draft}",
                    'payload' => ['message' => (new OutgoingChatMessage($person->ref, $person->name, $draft, [], null))->toArray()],
                ],
            ];
        }

        $address = $this->emailOf($meeting, $to);
        $sender = $this->outbox->senders($meeting->user)[0] ?? null;

        if ($address !== null && $sender !== null) {
            $email = new OutgoingEmail('new', $sender->accountId, $sender->email, [$address], [], Str::ucfirst($about ?: $meeting->title), $draft);

            return [
                'title' => 'Email para '.($address->name ?? $address->email).', listo',
                'body' => $draft,
                'dedupe_key' => $dedupe,
                'importance' => 'high',
                'action' => [
                    'type' => 'mail.send',
                    'label' => 'Email a '.MailAddress::names([$address]),
                    'preview' => "Email · {$draft}",
                    'payload' => ['email' => $email->toArray()],
                ],
            ];
        }

        return [
            'title' => "Mensaje para {$to}",
            'body' => "{$draft}\n\nNo sé cómo hacérselo llegar a {$to}: cópialo o dime por dónde.",
            'dedupe_key' => $dedupe,
        ];
    }

    /**
     * Issue keys the query may mean: the key itself, or a bare number in the
     * projects of the call's issues and of the Jira rules ("el 412" → CPD-412).
     *
     * @return list<string>
     */
    private function candidateKeys(WingmanMeeting $meeting, string $query): array
    {
        if (preg_match(self::ISSUE_KEY, Str::upper($query), $key)) {
            return [$key[0]];
        }

        if (! preg_match('/\b(\d{1,7})\b/', $query, $number)) {
            return [];
        }

        $projects = collect($meeting->context['issues'] ?? [])
            ->map(fn (array $issue): string => Str::before($issue['key'], '-'))
            ->merge(collect($this->issues->rules($meeting->user))->flatMap(fn (array $site): array => $site['rules']->projects()))
            ->unique()
            ->take(5);

        return $projects->map(fn (string $project): string => "{$project}-{$number[1]}")->values()->all();
    }

    private function slackPerson(WingmanMeeting $meeting, string $name): ?ChatPerson
    {
        $matches = $this->chats->people($meeting->user, $name, 5);
        $exact = array_values(array_filter($matches, fn (ChatPerson $person): bool => NameMatch::normalise($person->name) === NameMatch::normalise($name)));

        return match (true) {
            count($matches) === 1 => $matches[0],
            count($exact) === 1 => $exact[0],
            count($matches) > 1 && $matches[0]->activity > 0 && $matches[0]->activity >= 3 * $matches[1]->activity => $matches[0],
            default => null,
        };
    }

    /**
     * An attendee of the call with that name, or someone the mail knows.
     */
    private function emailOf(WingmanMeeting $meeting, string $name): ?MailAddress
    {
        $wanted = NameMatch::normalise($name);

        foreach ($this->others($meeting) as $person) {
            if ($person->email && str_contains(NameMatch::normalise((string) $person->name), $wanted)) {
                return $person;
            }
        }

        $found = $this->recipients->resolve($meeting->user, $name);

        return count($found) === 1 ? $found[0] : null;
    }

    /**
     * The attendees other than the person.
     *
     * @return list<MailAddress>
     */
    private function others(WingmanMeeting $meeting): array
    {
        $mine = Str::lower((string) $meeting->account);

        return collect($meeting->attendees ?? [])
            ->filter(fn (array $attendee): bool => filled($attendee['email'] ?? null) && Str::lower($attendee['email']) !== $mine && ($attendee['response'] ?? null) !== 'declined')
            ->map(fn (array $attendee): MailAddress => new MailAddress($attendee['name'] ?? null, $attendee['email']))
            ->values()
            ->all();
    }

    private function date(?string $date, string $timezone): ?CarbonImmutable
    {
        if (blank($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        return CarbonImmutable::parse($date, $timezone)->startOfDay();
    }
}
