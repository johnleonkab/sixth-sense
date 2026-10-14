<?php

use App\Actions\Actions;
use App\Actions\ActionStatus;
use App\Connectors\Calendar\CalendarReader;
use App\Connectors\Calendar\CalendarWriter;
use App\Connectors\Calendar\FakeCalendarReader;
use App\Connectors\Calendar\FakeCalendarWriter;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Connectors\Issues\FakeIssueTracker;
use App\Connectors\Issues\IssueTracker;
use App\Connectors\Mail\FakeMailbox;
use App\Connectors\Mail\FakeOutbox;
use App\Connectors\Mail\Mailbox;
use App\Connectors\Mail\Outbox;
use App\Connectors\Wiki\FakeWiki;
use App\Connectors\Wiki\Wiki;
use App\Events\WingmanMeetingUpdated;
use App\Jobs\ExecutePendingAction;
use App\Models\User;
use App\Models\WingmanAction;
use App\Models\WingmanMeeting;
use App\Models\WingmanSegment;
use App\Wingman\Closing\FollowUp;
use App\Wingman\Closing\GeminiFollowUpPlanner;
use App\Wingman\Closing\MeetingCloser;
use App\Wingman\Copilot\Detector;
use App\Wingman\Copilot\FakeDetector;
use App\Wingman\Copilot\WingmanAgent;
use App\Wingman\MeetingStatus;
use App\Wingman\Notes\MeetingNotes;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00', 'Europe/Madrid'));
    config(['agenda.timezone' => 'Europe/Madrid']);
    Event::fake([WingmanMeetingUpdated::class]);
    $this->app->instance(CalendarReader::class, new FakeCalendarReader);
    $this->app->instance(CalendarWriter::class, $this->calendar = new FakeCalendarWriter);
    $this->app->instance(IssueTracker::class, $this->issues = new FakeIssueTracker);
    $this->app->instance(Wiki::class, new FakeWiki);
    $this->app->instance(Chats::class, $this->chats = new FakeChats);
    $this->app->instance(Outbox::class, new FakeOutbox);
    $this->app->instance(Mailbox::class, new FakeMailbox);
    $this->app->instance(Detector::class, new FakeDetector);
    $this->user = User::factory()->create(['name' => 'John León', 'wingman_enabled' => true]);
    $this->meeting = WingmanMeeting::factory()->for($this->user)->create([
        'status' => MeetingStatus::Ended,
        'title' => 'Revisión flujo de prepago',
        'account' => 'demo@sixth-sense.test',
        'attendees' => [['name' => 'Marta Ruiz', 'email' => 'marta@covermanager.test'], ['name' => 'John León', 'email' => 'demo@sixth-sense.test']],
    ]);
});

test('closing takes the last lines into the notes and makes the person\'s commitments ready to review', function () {
    WingmanSegment::factory()->for($this->meeting, 'meeting')->create(['speaker' => 'Tú', 'is_me' => true, 'text' => 'Bueno, pues eso es todo por hoy', 'final' => true]);
    $this->meeting->update(['notes' => (new MeetingNotes(summary: 'Demo del viernes.', commitments: [
        ['who' => 'Tú', 'what' => 'Escribir a Marta con el resumen del alcance', 'when' => null],
        ['who' => 'Tú', 'what' => 'Crear el ticket del reembolso parcial en Jira', 'when' => null],
        ['who' => 'Tú', 'what' => 'Preparar la demo del viernes', 'when' => 'viernes'],
        ['who' => 'Pablo Gil', 'what' => 'Terminar la pasarela', 'when' => 'jueves'],
    ]))->toArray()]);

    $ready = app(MeetingCloser::class)->close($this->meeting);

    expect($this->meeting->refresh()->closed_at)->not->toBeNull()
        // The last line made it into the notes; the transcript itself is not kept.
        ->and(collect(MeetingNotes::fromArray($this->meeting->notes)->topics)->flatMap(fn (array $topic) => $topic['points'])->all())->toContain('Tú: Bueno, pues eso es todo por hoy')
        ->and($this->meeting->segments()->count())->toBe(0)
        ->and(collect($ready)->pluck('kind')->all())->toBe(['message', 'issue', 'task'])
        ->and($ready[0]->type)->toBe('chat.send')
        ->and($ready[0]->payload['message']['to'])->toBe('demo:U01')
        ->and($ready[1]->type)->toBe('jira.create')
        ->and($ready[1]->payload['issue']['projectKey'])->toBe('CPD')
        ->and($ready[2]->type)->toBe('agenda.schedule')
        ->and($ready[2]->payload['title'])->toBe('Preparar la demo del viernes');
    Event::assertDispatched(WingmanMeetingUpdated::class);

    // Closing again (the person came back and left again) does not repeat them.
    expect(app(MeetingCloser::class)->close($this->meeting->refresh()))->toBe([])
        ->and($this->meeting->actions()->count())->toBe(3);
});

test('a card with something ready goes to the meeting\'s list at once', function () {
    $this->meeting->update(['status' => MeetingStatus::Live]);
    WingmanSegment::factory()->for($this->meeting, 'meeting')->create(['speaker' => 'Tú', 'is_me' => true, 'text' => 'Le escribo a Marta sobre el alcance de la demo', 'final' => true]);

    [$card] = app(WingmanAgent::class)->watch($this->meeting);
    $action = $this->meeting->actions()->sole();

    expect($action->wingman_insight_id)->toBe($card->id)
        ->and($action->kind)->toBe('message')
        ->and($action->type)->toBe('chat.send')
        ->and($action->state())->toBe('proposed');
});

test('reviewing makes it a change waiting for the "sí"; confirmed, it is done after the undo window', function () {
    Queue::fake();
    $action = WingmanAction::factory()->for($this->meeting, 'meeting')->create();

    $this->actingAs($this->user)->postJson(route('wingman.actions.review', $action->id))
        ->assertOk()
        ->assertJsonPath('action.state', 'reviewing')
        ->assertJsonPath('action.action.status', 'pending');

    // Reviewing twice does not propose it twice.
    $this->actingAs($this->user)->postJson(route('wingman.actions.review', $action->id));
    expect($this->user->pendingActions()->count())->toBe(1);

    $pending = $action->refresh()->pendingAction;
    app(Actions::class)->confirm($pending);
    expect($action->refresh()->state())->toBe('running');

    (new ExecutePendingAction($pending->refresh()))->handle(app(Actions::class));

    expect($action->refresh()->state())->toBe('done')
        ->and($this->chats->sent[0]->text)->toBe('Hola Marta, te resumo lo acordado.');
});

test('an action can be dropped and brought back, and cancelled ones can be reviewed again', function () {
    $action = WingmanAction::factory()->for($this->meeting, 'meeting')->create();

    $this->actingAs($this->user)->postJson(route('wingman.actions.dismiss', $action->id))->assertJsonPath('action.state', 'dismissed');
    $this->actingAs($this->user)->postJson(route('wingman.actions.restore', $action->id))->assertJsonPath('action.state', 'proposed');

    $this->actingAs($this->user)->postJson(route('wingman.actions.review', $action->id));
    app(Actions::class)->cancel($action->refresh()->pendingAction);

    expect($action->refresh()->state())->toBe('proposed');
    $this->actingAs($this->user)->postJson(route('wingman.actions.review', $action->id))->assertJsonPath('action.state', 'reviewing');
    expect($action->refresh()->pendingAction->status)->toBe(ActionStatus::Pending);
});

test("someone else's actions can not be touched", function () {
    $action = WingmanAction::factory()->create();

    $this->actingAs($this->user)->postJson(route('wingman.actions.review', $action->id))->assertNotFound();
    $this->actingAs($this->user)->postJson(route('wingman.actions.dismiss', $action->id))->assertNotFound();
});

test('own tasks are booked by the scheduler once confirmed', function () {
    $this->meeting->update(['notes' => (new MeetingNotes(commitments: [['who' => 'Tú', 'what' => 'Preparar la demo del viernes', 'when' => null]]))->toArray()]);
    [$action] = app(MeetingCloser::class)->close($this->meeting);

    $pending = app(Actions::class)->propose($this->user, $action->type, $action->label, $action->payload);
    $result = app(Actions::class)->handlerFor($pending)->execute($pending);

    $task = $this->user->agendaTasks()->sole();

    expect($task->title)->toBe('Preparar la demo del viernes')
        ->and($task->origin)->toBe('wingman')
        ->and($result['task_id'])->toBe($task->id);
});

test('the meeting page lists what is left to do', function () {
    WingmanAction::factory()->for($this->meeting, 'meeting')->create();
    $this->meeting->update(['closed_at' => now()]);

    $this->actingAs($this->user)->get(route('wingman.show', $this->meeting->id))
        ->assertInertia(fn ($page) => $page->where('meeting.actions.0.type', 'chat.send')->where('meeting.actions.0.state', 'proposed')->whereNot('meeting.closed_at', null));
});

test('gemini plans the follow-ups from the final notes and what is already prepared', function () {
    Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['followups' => [
        ['kind' => 'message', 'title' => 'Escribir a Marta', 'to' => 'Marta', 'about' => 'el alcance', 'draft' => 'Hola Marta…'],
        ['kind' => 'nonsense', 'title' => 'x'],
    ]])]]]]]])]);
    $planner = new GeminiFollowUpPlanner(['api_key' => 'k', 'base_url' => 'https://generativelanguage.googleapis.com', 'model' => 'm', 'thinking_level' => null, 'timeout' => 5]);

    $followUps = $planner->plan($this->meeting, new MeetingNotes(summary: 'Demo', commitments: [['who' => 'Tú', 'what' => 'Escribir a Marta', 'when' => null]]), ['Convocar «Seguimiento»']);

    expect($followUps)->toHaveCount(1)
        ->and($followUps[0])->toBeInstanceOf(FollowUp::class)
        ->and($followUps[0]->details)->toMatchArray(['to' => 'Marta', 'draft' => 'Hola Marta…']);
    Http::assertSent(fn (Request $request) => str_contains($request['contents'][0]['parts'][0]['text'], "Ya preparado:\n- Convocar «Seguimiento»"));
});
