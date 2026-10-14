<?php

namespace App\Wingman;

use App\Connectors\Calendar\CalendarReader;
use App\Connectors\Issues\IssueTracker;
use App\Connectors\Mail\Mailbox;
use App\Connectors\Mail\MailMessage;
use App\Connectors\Mail\MailSearch;
use App\Connectors\Wiki\Wiki;
use App\Connectors\Wiki\WikiPageSummary;
use App\Models\WingmanMeeting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * What the person should have at hand before a call: the documents of the
 * event, the Jira issues and Notion pages it mentions (or is about), and the
 * latest emails with the attendees. Each part is gathered on its own: one
 * service failing leaves the rest.
 */
class MeetingContext
{
    private const ISSUE_KEY = '/\b[A-Z][A-Z0-9]{1,9}-\d+\b/';

    private const NOTION_LINK = '~https?://(?:www\.)?notion\.(?:so|site)/[^\s)>\]"]+~i';

    public function __construct(
        private CalendarReader $calendar,
        private IssueTracker $issues,
        private Wiki $wiki,
        private Mailbox $mailbox,
    ) {}

    /**
     * Gather it and keep it with the meeting.
     */
    public function prepare(WingmanMeeting $meeting): WingmanMeeting
    {
        $failed = [];
        $part = function (string $name, callable $gather) use (&$failed): array {
            try {
                return $gather();
            } catch (Throwable $exception) {
                report($exception);
                $failed[] = $name;

                return [];
            }
        };

        $meeting->update([
            'context' => [
                'documents' => $part('calendar', fn (): array => $this->documents($meeting)),
                'issues' => $part('jira', fn (): array => $this->issuesOf($meeting)),
                'pages' => $part('notion', fn (): array => $this->pagesOf($meeting)),
                'emails' => $part('gmail', fn (): array => $this->emailsWith($meeting)),
                'failed' => $failed,
            ],
            'context_prepared_at' => now(),
        ]);

        return $meeting;
    }

    /**
     * @return list<array{title: ?string, url: string}>
     */
    private function documents(WingmanMeeting $meeting): array
    {
        if ($meeting->event_ref === null) {
            return [];
        }

        return $this->calendar->event($meeting->user, $meeting->event_ref, (string) config('agenda.timezone'))?->documents ?? [];
    }

    /**
     * The issues the event names ("CPD-412"), as they stand now.
     *
     * @return list<array<string, mixed>>
     */
    private function issuesOf(WingmanMeeting $meeting): array
    {
        preg_match_all(self::ISSUE_KEY, "{$meeting->title}\n{$meeting->description}", $keys);
        $found = [];

        foreach (array_slice(array_unique($keys[0]), 0, (int) config('wingman.context.issues')) as $key) {
            $issue = $this->issues->issue($meeting->user, $key);

            if ($issue !== null) {
                $found[] = [
                    ...$issue->summary->toArray((string) config('agenda.timezone')),
                    'blocked_by' => collect($issue->links)->where('relation', 'is blocked by')->pluck('key')->values()->all(),
                ];
            }
        }

        return $found;
    }

    /**
     * Pages the event links to, then pages about its title.
     *
     * @return list<array{ref: string, title: string, url: ?string, linked: bool}>
     */
    private function pagesOf(WingmanMeeting $meeting): array
    {
        $limit = (int) config('wingman.context.pages');
        $pages = [];

        preg_match_all(self::NOTION_LINK, (string) $meeting->description, $links);

        foreach (array_unique($links[0]) as $link) {
            $page = $this->wiki->page($meeting->user, $link);

            if ($page !== null) {
                $pages[$page->ref] = ['ref' => $page->ref, 'title' => $page->title, 'url' => $page->url, 'linked' => true];
            }
        }

        if (count($pages) < $limit && Str::length($this->topic($meeting->title)) >= 4) {
            foreach ($this->wiki->search($meeting->user, $this->topic($meeting->title), $limit) as $summary) {
                /** @var WikiPageSummary $summary */
                $pages[$summary->ref] ??= ['ref' => $summary->ref, 'title' => $summary->title, 'url' => $summary->url, 'linked' => false];
            }
        }

        return array_values(array_slice($pages, 0, $limit));
    }

    /**
     * The latest emails from the attendees (not the person themselves).
     *
     * @return list<array{ref: string, from: ?string, subject: string, date: string, snippet: string, url: ?string}>
     */
    private function emailsWith(WingmanMeeting $meeting): array
    {
        $after = CarbonImmutable::now()->subDays((int) config('wingman.context.email_days'));
        $mine = Str::lower((string) $meeting->account);
        $emails = collect($meeting->attendees ?? [])
            ->pluck('email')
            ->filter(fn (?string $email): bool => filled($email) && Str::lower($email) !== $mine)
            ->unique()
            ->take((int) config('wingman.context.email_attendees'));

        $messages = [];

        foreach ($emails as $email) {
            foreach ($this->mailbox->search($meeting->user, new MailSearch(from: $email, after: $after, limit: 3))->messages as $found) {
                $messages[$found->message->ref] = $found->message;
            }
        }

        return collect($messages)
            ->sortByDesc(fn (MailMessage $message): int => $message->date->getTimestamp())
            ->take((int) config('wingman.context.emails'))
            ->map(fn (MailMessage $message): array => [
                'ref' => $message->ref,
                'from' => $message->from?->label(),
                'subject' => $message->subject,
                'date' => $message->date->toIso8601String(),
                'snippet' => Str::limit($message->snippet, 200),
                'url' => $message->url,
            ])
            ->values()
            ->all();
    }

    /**
     * The title without what says nothing of the topic ("Sync", "1:1 con").
     */
    private function topic(string $title): string
    {
        return trim((string) preg_replace('/\b(sync|daily|weekly|1:1|one on one|reuni[oó]n|llamada|call|con|with|de|del|y|and)\b/iu', ' ', preg_replace(self::ISSUE_KEY, ' ', $title)));
    }
}
