<?php

use App\Connectors\Calendar\CalendarReader;
use App\Connectors\Calendar\FakeCalendarReader;
use App\Connectors\Issues\FakeIssueTracker;
use App\Connectors\Issues\IssueTracker;
use App\Connectors\Mail\FakeMailbox;
use App\Connectors\Mail\Mailbox;
use App\Connectors\Wiki\FakeWiki;
use App\Connectors\Wiki\Wiki;
use App\Models\User;
use App\Models\WingmanMeeting;
use App\Wingman\MeetingContext;

beforeEach(function () {
    $this->app->instance(CalendarReader::class, new FakeCalendarReader);
    $this->app->instance(IssueTracker::class, new FakeIssueTracker);
    $this->app->instance(Wiki::class, new FakeWiki);
    $this->app->instance(Mailbox::class, new FakeMailbox);
    $this->meeting = WingmanMeeting::factory()->for(User::factory()->create())->create([
        'title' => 'Revisión flujo de prepago',
        'account' => 'demo@sixth-sense.test',
        'attendees' => [
            ['name' => 'Marta Ruiz', 'email' => 'marta@covermanager.test'],
            ['name' => 'John León', 'email' => 'demo@sixth-sense.test'],
        ],
        'description' => "Revisamos dónde está CPD-412 antes del sprint.\nPlan: https://www.notion.so/covermanager/Plan-de-lanzamiento-prepago-1a2b3c4d000040008000000000000001",
    ]);
});

test('before a call, the issues and pages it mentions and the latest emails with its attendees are gathered', function () {
    $context = app(MeetingContext::class)->prepare($this->meeting)->refresh()->context;

    expect($context['failed'])->toBe([])
        ->and($context['issues'])->toHaveCount(1)
        ->and($context['issues'][0])->toMatchArray(['key' => 'CPD-412', 'blocked_by' => ['CPD-405']])
        ->and($context['pages'][0])->toMatchArray(['title' => 'Plan de lanzamiento: prepago', 'linked' => true])
        ->and($context['emails'])->not->toBeEmpty()
        ->and(collect($context['emails'])->pluck('from')->unique()->all())->toBe(['Marta Ruiz'])
        ->and($this->meeting->context_prepared_at)->not->toBeNull();
});

test('a service that fails leaves the rest of the context', function () {
    $this->app->instance(IssueTracker::class, new class extends FakeIssueTracker
    {
        public function issue(User $user, string $reference): never
        {
            throw new RuntimeException('Jira is down');
        }
    });

    $context = app(MeetingContext::class)->prepare($this->meeting)->refresh()->context;

    expect($context['failed'])->toBe(['jira'])
        ->and($context['issues'])->toBe([])
        ->and($context['pages'])->not->toBeEmpty();
});
