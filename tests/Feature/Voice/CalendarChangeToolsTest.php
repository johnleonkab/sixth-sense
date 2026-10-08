<?php

use App\Actions\ActionStatus;
use App\Connectors\Calendar\CalendarReader;
use App\Connectors\Calendar\CalendarWriter;
use App\Connectors\Calendar\FakeCalendarReader;
use App\Connectors\Calendar\FakeCalendarWriter;
use App\Connectors\Mail\FakeMailbox;
use App\Connectors\Mail\Mailbox;
use App\Models\PendingAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 08:00:00', 'UTC'));
    $this->app->instance(CalendarReader::class, new FakeCalendarReader);
    $this->app->instance(CalendarWriter::class, $this->writer = new FakeCalendarWriter);
    $this->app->instance(Mailbox::class, new FakeMailbox);
    $this->user = User::factory()->create();
    // In the sample day, the user organises the 1:1 (index 2) and is invited to the daily (index 0).
    $this->organised = 'demo:primary:20261008-2';
    $this->invited = 'demo:primary:20261008-0';
});

function runCalendarTool(string $tool, array $arguments): TestResponse
{
    return test()->actingAs(test()->user)->postJson(route('voice.tools.run', $tool), ['arguments' => $arguments, 'timezone' => 'Europe/Madrid']);
}

test('events found by voice carry the id to change them', function () {
    runCalendarTool('find_calendar_events', ['start_date' => '2026-10-08'])
        ->assertJsonPath('output.events.2.event_id', $this->organised)
        ->assertJsonPath('output.events.2.organized_by_user', true)
        ->assertJsonPath('output.events.0.my_response', 'needsAction');
});

test('inviting someone shows the new guest as a draft and changes nothing yet', function () {
    runCalendarTool('propose_event_invite', ['event_id' => $this->organised, 'people' => ['Pablo']])
        ->assertJsonPath('canvas.view', 'calendar.change')
        ->assertJsonPath('canvas.props.kind', 'invite')
        ->assertJsonPath('canvas.props.new_people.0.email', 'pablo@covermanager.test')
        ->assertJsonPath('canvas.props.event.title', '1:1 con Marta');

    expect($this->writer->changes)->toBe([]);
});

test('once confirmed the invitation is made', function () {
    $proposal = runCalendarTool('propose_event_invite', ['event_id' => $this->organised, 'people' => ['Pablo']]);

    $this->actingAs($this->user)->postJson(route('actions.confirm', $proposal->json('output.pending_action_id')));

    expect(PendingAction::sole()->status)->toBe(ActionStatus::Executed)
        ->and($this->writer->changes)->toBe([['invite', ['ref' => $this->organised, 'people' => ['pablo@covermanager.test']]]]);
});

test('meetings the user does not organise can not be changed, only answered', function (string $tool, array $arguments) {
    runCalendarTool($tool, ['event_id' => $this->invited, ...$arguments])
        ->assertJsonPath('canvas', null)
        ->assertJsonPath('output.error', fn (string $error) => str_contains($error, 'no organiza'));
})->with([
    'invite' => ['propose_event_invite', ['people' => ['Pablo']]],
    'summary' => ['propose_event_summary', ['summary' => 'Ok']],
    'cancel' => ['propose_event_cancellation', []],
]);

test('a summary is added below the description with today date', function () {
    runCalendarTool('propose_event_summary', ['event_id' => $this->organised, 'summary' => '- Marta revisa el paso 3'])
        ->assertJsonPath('canvas.props.kind', 'summary')
        ->assertJsonPath('canvas.props.heading', 'Resumen · 8 de octubre de 2026')
        ->assertJsonPath('canvas.props.summary', '- Marta revisa el paso 3');
});

test('a new meeting warns about what it clashes with', function () {
    runCalendarTool('propose_new_event', ['title' => 'Revisión prepago', 'date' => '2026-10-08', 'start_time' => '11:30', 'people' => ['Marta']])
        ->assertJsonPath('canvas.props.kind', 'create')
        ->assertJsonPath('canvas.props.new_event.end', '2026-10-08T12:00:00+02:00')
        ->assertJsonPath('canvas.props.new_event.video_call', true)
        ->assertJsonPath('canvas.props.new_event.attendees.0.email', 'marta@covermanager.test')
        ->assertJsonPath('canvas.props.conflicts.0.title', 'Revisión flujo de prepago')
        ->assertJsonPath('output.conflict_count', 1);
});

test('cancelling a meeting the user organises says who will be told', function () {
    runCalendarTool('propose_event_cancellation', ['event_id' => $this->organised])
        ->assertJsonPath('canvas.props.kind', 'cancel')
        ->assertJsonPath('canvas.props.event.title', '1:1 con Marta');
});

test('answering an invitation shows the answer and the previous one', function () {
    runCalendarTool('propose_event_response', ['event_id' => $this->invited, 'response' => 'tentative', 'comment' => 'Llego tarde'])
        ->assertJsonPath('canvas.props.kind', 'respond')
        ->assertJsonPath('canvas.props.response_label', 'Quizá')
        ->assertJsonPath('canvas.props.previous_response', 'needsAction')
        ->assertJsonPath('canvas.props.comment', 'Llego tarde');
});

test('a meeting the user organises has no invitation to answer', function () {
    runCalendarTool('propose_event_response', ['event_id' => $this->organised, 'response' => 'declined'])
        ->assertJsonStructure(['output' => ['error']]);
});
