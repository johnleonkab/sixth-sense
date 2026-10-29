<?php

use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Alerts\Judges\LlmMeetingAdvisor;
use App\Connectors\Calendar\CalendarEvent;
use App\Connectors\Calendar\CalendarReader;
use App\Connectors\Calendar\CalendarReading;
use App\Connectors\Calendar\EventQuery;
use App\Connectors\Docs\Documents;
use App\Connectors\Docs\DocumentSearchResult;
use App\Connectors\Docs\DocumentSummary;
use App\Connectors\Issues\FakeIssueTracker;
use App\Connectors\Issues\IssueTracker;
use App\Connectors\Wiki\FakeWiki;
use App\Connectors\Wiki\Wiki;
use App\Jobs\FindKnowledgeGaps;
use App\Knowledge\Gaps\GapKind;
use App\Knowledge\Gaps\GapQuestions;
use App\Knowledge\Gaps\GapStatus;
use App\Knowledge\Gaps\MeetingGaps;
use App\Knowledge\Gaps\TermGaps;
use App\Knowledge\KnowledgeBase;
use App\Knowledge\KnowledgeSource;
use App\Knowledge\KnowledgeSync;
use App\Models\ConnectedAccount;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeGap;
use App\Models\User;
use App\Models\WingmanMeeting;
use App\Voice\AssistantProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecordingTextModel;

/**
 * A calendar whose week the test writes.
 */
class GapsCalendar implements CalendarReader
{
    /** @var list<CalendarEvent> */
    public array $events = [];

    public function read(User $user, EventQuery $query): CalendarReading
    {
        return new CalendarReading($this->events, ['jhon@work.com']);
    }

    public function event(User $user, string $ref, string $timezone): ?CalendarEvent
    {
        return null;
    }

    public function busy(User $user, array $emails, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [];
    }
}

function weekMeeting(string $title, string $uid, int $inHours, array $overrides = []): CalendarEvent
{
    $start = CarbonImmutable::parse('2026-11-02 10:00', 'UTC')->addHours($inHours);

    return new CalendarEvent(...[
        'id' => $uid.'-'.$inHours,
        'title' => $title,
        'start' => $start,
        'end' => $start->addMinutes(30),
        'allDay' => false,
        'account' => 'jhon@work.com',
        'attendees' => [['name' => 'John', 'email' => 'jhon@work.com'], ['name' => 'Marta Ruiz', 'email' => 'marta@work.com'], ['name' => 'Pablo Gil', 'email' => 'pablo@work.com']],
        'organizer' => 'Marta Ruiz',
        'uniqueKey' => "{$uid}@google.com@".$start->toIso8601String(),
        ...$overrides,
    ]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->calendar = new GapsCalendar;
    $this->app->instance(CalendarReader::class, $this->calendar);
});

test('an unexplained meeting becomes one question, once per series', function () {
    $this->calendar->events = [
        weekMeeting('Sync Ops', 'ops', 2),
        weekMeeting('Sync Ops', 'ops', 26),
        weekMeeting('Revisión prepago', 'prepago', 3, ['description' => 'Repasamos el flujo de prepago con Producto y decidimos el alcance del reembolso parcial.']),
        weekMeeting('Kick-off cliente', 'kickoff', 4, ['documents' => [['title' => 'Agenda', 'url' => 'https://docs.google.com/document/d/abc/edit']]]),
        weekMeeting('Bloque de foco', 'focus', 5, ['flexible' => true]),
        weekMeeting('Café', 'cafe', 6, ['declined' => true]),
        weekMeeting('Solo', 'solo', 7, ['attendees' => [['name' => 'John', 'email' => 'jhon@work.com']]]),
    ];

    expect(app(MeetingGaps::class)->find($this->user))->toBe(1)
        ->and(app(MeetingGaps::class)->find($this->user))->toBe(0);

    $gap = $this->user->knowledgeGaps()->sole();

    expect($gap->kind)->toBe(GapKind::Meeting)
        ->and($gap->subject_key)->toBe('ops@google.com')
        ->and($gap->question)->toContain('«Sync Ops»')->toContain('Se repite (2 veces esta semana)')->toContain('3 personas')
        ->and($gap->context['recurring'])->toBeTrue()
        ->and($gap->next_occurrence_at->toIso8601String())->toBe('2026-11-02T12:00:00+00:00');
});

test('a meeting with past notes in the knowledge base is not asked about', function () {
    KnowledgeDocument::factory()->for($this->user)->create(['source' => KnowledgeSource::Meeting, 'title' => 'Sync Ops - 2026/10/26 10:00 CET - Notes by Gemini']);
    $this->calendar->events = [weekMeeting('Sync Ops', 'ops', 2)];

    expect(app(MeetingGaps::class)->find($this->user))->toBe(0);
});

test('questions are asked quietly, three a day, meetings first and only in working hours', function () {
    KnowledgeGap::factory()->for($this->user)->count(3)->sequence(
        ['subject' => 'Sync A', 'next_occurrence_at' => '2026-11-03 10:00'],
        ['subject' => 'Sync B', 'next_occurrence_at' => '2026-11-02 12:00'],
        ['subject' => 'Sync C', 'next_occurrence_at' => '2026-11-04 10:00'],
    )->create(['context' => ['recurring' => true]]);
    KnowledgeGap::factory()->for($this->user)->create(['kind' => GapKind::Term, 'subject' => 'KYB', 'subject_key' => 'KYB', 'priority' => 99]);

    $questions = app(GapQuestions::class);

    expect($questions->ask($this->user, CarbonImmutable::parse('2026-11-02 06:00', 'UTC')))->toBe(0)
        ->and($questions->ask($this->user))->toBe(3)
        ->and($questions->ask($this->user))->toBe(0);

    $alerts = $this->user->inboxItems()->where('source', AlertSource::Questions)->orderBy('id')->get();

    expect($alerts->pluck('title')->all())->toBe(['¿Para qué es «Sync B»?', '¿Para qué es «Sync A»?', '¿Para qué es «Sync C»?'])
        ->and($alerts->first()->state())->toBe('question')
        ->and($this->user->knowledgeGaps()->where('status', GapStatus::Asked)->count())->toBe(3);
});

test('turning questions off stops them', function () {
    KnowledgeGap::factory()->for($this->user)->create(['next_occurrence_at' => '2026-11-03 10:00']);
    $this->user->alertSettings()->create(['source' => AlertSource::Questions, 'enabled' => false]);

    expect(app(GapQuestions::class)->ask($this->user))->toBe(0);
});

test('an answer by voice closes the question and becomes memory every agent reads', function () {
    $gap = KnowledgeGap::factory()->for($this->user)->create([
        'subject' => 'Sync Ops',
        'subject_key' => 'ops@google.com',
        'next_occurrence_at' => '2026-11-02 12:00',
        'context' => ['recurring' => true],
    ]);
    app(GapQuestions::class)->ask($this->user);
    $alert = $this->user->inboxItems()->where('source', AlertSource::Questions)->sole();

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'answer_question'), ['arguments' => [
            'alert_id' => $alert->id,
            'answer' => 'Revisión semanal de incidencias con Soporte; John decide prioridades; imprescindible.',
        ]])
        ->assertOk()
        ->assertJsonPath('output.status', 'answered');

    expect($gap->fresh()->status)->toBe(GapStatus::Answered)
        ->and($alert->fresh()->status)->toBe(AlertStatus::Resolved)
        ->and(app(KnowledgeBase::class)->search($this->user, 'incidencias soporte')[0]['source'])->toBe('Memoria')
        ->and(AssistantProfile::for($this->user, CarbonImmutable::now())->instructions)->toContain('Reunión «Sync Ops»: Revisión semanal de incidencias');

    $this->calendar->events = [weekMeeting('Sync Ops', 'ops', 2)];

    expect(app(MeetingGaps::class)->find($this->user))->toBe(0);
});

test('a dismissed question is never asked again', function () {
    $gap = KnowledgeGap::factory()->for($this->user)->create(['next_occurrence_at' => '2026-11-02 12:00']);
    app(GapQuestions::class)->ask($this->user);
    $alert = $this->user->inboxItems()->sole();

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'answer_question'), ['arguments' => ['alert_id' => $alert->id, 'dismiss' => true]])
        ->assertJsonPath('output.status', 'dismissed');

    expect($gap->fresh()->status)->toBe(GapStatus::Dismissed)
        ->and($alert->fresh()->status)->toBe(AlertStatus::Dismissed)
        ->and(app(GapQuestions::class)->ask($this->user))->toBe(0);
});

test('the meeting advisor reads what the person explained about the meeting', function () {
    KnowledgeGap::factory()->for($this->user)->answered('Revisión de incidencias; hay que traer el informe de tickets.')->create(['subject_key' => 'ops@google.com']);
    $model = new RecordingTextModel(['needs_preparation' => true, 'lead_minutes' => 15, 'preparation' => 'Trae el informe de tickets.']);

    (new LlmMeetingAdvisor($model))->advise($this->user, weekMeeting('Sync Ops', 'ops', 2));

    expect($model->calls[0]['user'])->toContain('hay que traer el informe de tickets');
});

test('repeated acronyms the archive does not explain become questions', function () {
    foreach (['CPD-412 lanza KYB para Pagos', 'El KYB bloquea el alta', 'Revisar KYB con legal, y la API', 'Pagar con ABC'] as $i => $content) {
        KnowledgeDocument::factory()->for($this->user)->create(['source' => KnowledgeSource::Jira, 'ref' => "demo:CPD-{$i}", 'title' => "CPD-{$i}", 'content' => "{$content}. ABC y CPD otra vez."]);
    }
    KnowledgeDocument::factory()->for($this->user)->create(['source' => KnowledgeSource::Archive, 'content' => 'ABC es el proveedor de pagos.']);

    expect(TermGaps::terms('CPD-412 y el KYB, la API y 2026'))->toBe(['KYB'])
        ->and(app(TermGaps::class)->find($this->user))->toBe(2)
        ->and($this->user->knowledgeGaps()->where('kind', GapKind::Term)->orderBy('subject')->pluck('subject')->all())->toBe(['CPD', 'KYB'])
        ->and(app(TermGaps::class)->find($this->user))->toBe(0);
});

test('meeting notes from Drive, with every tab, and Wingman notes are copied as meetings', function () {
    $this->app->instance(IssueTracker::class, new FakeIssueTracker);
    $this->app->instance(Wiki::class, new FakeWiki);
    $notes = new DocumentSummary('1.notes', 'jhon@work.com', 'Sync Ops - 2026/10/30 10:00 CET - Notas de Gemini', null, CarbonImmutable::parse('2026-10-30 10:00'), CarbonImmutable::parse('2026-10-30 11:00'), url: 'https://docs.google.com/document/d/notes/edit');
    $other = new DocumentSummary('1.other', 'jhon@work.com', 'Presupuesto 2027', null, CarbonImmutable::parse('2026-10-30 10:00'), CarbonImmutable::parse('2026-10-30 11:00'));
    $this->app->instance(Documents::class, Mockery::mock(Documents::class, function ($documents) use ($notes, $other) {
        $documents->shouldReceive('search')->andReturn(new DocumentSearchResult([$notes, $other], ['jhon@work.com']));
        $documents->shouldReceive('fullText')->with(Mockery::any(), '1.notes')->andReturn("## Notas\n\nResumen: se decide congelar el KYB.\n\n## Transcripción\n\n00:01:02\nMarta Ruiz: lo congelamos.");
    }));
    WingmanMeeting::factory()->for($this->user)->create([
        'title' => 'Revisión flujo de prepago',
        'notes' => ['summary' => 'Se acuerda devolver el reembolso en 48 horas.', 'decisions' => ['Reembolso en 48 horas']],
        'notes_updated_at' => now()->subDay(),
    ]);

    app(KnowledgeSync::class)->sync($this->user);

    $meetings = $this->user->knowledgeDocuments()->where('source', KnowledgeSource::Meeting)->orderBy('ref')->get();

    expect($meetings->pluck('ref')->all())->toHaveCount(2)
        ->and($meetings->firstWhere('ref', '1.notes')->content)->toContain('Marta Ruiz: lo congelamos.')
        ->and($meetings->first(fn (KnowledgeDocument $document): bool => str_starts_with($document->ref, 'wingman:'))->content)->toContain('Reembolso en 48 horas')
        ->and(app(KnowledgeBase::class)->search($this->user, 'congelar KYB', [KnowledgeSource::Meeting])[0]['ref'])->toBe('1.notes');
});

test('the hourly command queues a look for each connected person', function () {
    Queue::fake();
    $connected = ConnectedAccount::factory()->create()->user;

    $this->artisan('knowledge:ask')->assertSuccessful();

    Queue::assertPushed(FindKnowledgeGaps::class, fn (FindKnowledgeGaps $job): bool => $job->user->is($connected) && $job->queue === 'context');
});
