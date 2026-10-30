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
    $proposal = runCalendarTool('propose_event_invite', ['event_id' => $this->organised, 'people' => ['Pablo']])
        ->assertJsonPath('canvas.view', 'openui.view')
        ->assertJsonPath('canvas.props.source', 'Calendar · Invitación')
        ->assertJsonPath('beside', null);

    expect($proposal->json('canvas.props.ui'))
        ->toContain('Card("1:1 con Marta"')
        ->toContain('Draft("Nuevos invitados", "Pablo Gil")')
        ->toContain('Proposal('.$proposal->json('output.pending_action_id').', "invitar", "Invitaciones enviadas")')
        ->toBeValidOpenUi();

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
    expect(runCalendarTool('propose_event_summary', ['event_id' => $this->organised, 'summary' => '- Marta revisa el paso 3'])->json('canvas.props.ui'))
        ->toContain('Draft("— Resumen · 8 de octubre de 2026 —", "- Marta revisa el paso 3")')
        ->toBeValidOpenUi();
});

test('a new meeting warns about what it clashes with', function () {
    $proposal = runCalendarTool('propose_new_event', ['title' => 'Revisión prepago', 'date' => '2026-10-08', 'start_time' => '11:30', 'people' => ['Marta']])
        ->assertJsonPath('beside.view', 'calendar.day')
        ->assertJsonPath('beside.props.from', '2026-10-08')
        ->assertJsonPath('output.conflict_count', 1);

    expect($proposal->json('canvas.props.ui'))
        ->toContain('Draft("Reunión nueva · borrador", "Revisión prepago\\nJueves 8 de octubre · 11:30–12:00\\n')
        ->toContain('Google Meet')
        ->toContain('Con Marta Ruiz')
        ->toContain('Text("Choca con «Revisión flujo de prepago» (11:00–11:45).", "warn")')
        ->toBeValidOpenUi();
});

test('cancelling a meeting the user organises says who will be told', function () {
    expect(runCalendarTool('propose_event_cancellation', ['event_id' => $this->organised])->json('canvas.props.ui'))
        ->toContain('Card("1:1 con Marta"')
        ->toContain('Text("Se borrará de tu calendario')
        ->toContain('"cancelar", "Reunión cancelada"')
        ->toBeValidOpenUi();
});

test('answering an invitation shows the answer and the previous one', function () {
    expect(runCalendarTool('propose_event_response', ['event_id' => $this->invited, 'response' => 'tentative', 'comment' => 'Llego tarde'])->json('canvas.props.ui'))
        ->toContain('Draft("Tu respuesta, antes «Sin responder»", "Quizá\\n«Llego tarde»")')
        ->toBeValidOpenUi();
});

test('a meeting the user organises has no invitation to answer', function () {
    runCalendarTool('propose_event_response', ['event_id' => $this->organised, 'response' => 'declined'])
        ->assertJsonStructure(['output' => ['error']]);
});

test('moving a meeting keeps its length and shows the before and after', function () {
    $sync = 'demo:primary:20261008-3';

    $proposal = runCalendarTool('propose_event_update', ['event_id' => $sync, 'date' => '2026-10-09', 'start_time' => '12:00'])
        ->assertJsonPath('output.change', 'Mover «Sync producto–ventas»');

    expect($proposal->json('canvas.props.ui'))
        ->toContain('Table(["", "Antes", "Después"], [["Cuándo", "jueves 8 de octubre, 13:00–14:00", "viernes 9 de octubre, 12:00–13:00"]])')
        ->toContain('Text("Se avisará a los invitados del cambio.", "muted")')
        ->toContain('Text("Choca con «1:1 con Marta» (12:00–12:30).", "warn")')
        ->toBeValidOpenUi();

    expect($this->writer->changes)->toBe([]);
});

test('once confirmed the meeting moves, renamed and without who was taken off', function () {
    $proposal = runCalendarTool('propose_event_update', [
        'event_id' => 'demo:primary:20261008-3',
        'start_time' => '15:00',
        'duration_minutes' => 30,
        'title' => 'Sync ventas',
        'remove_people' => ['Lucía'],
        'notify' => false,
    ]);

    expect($proposal->json('canvas.props.ui'))->toContain('Draft("Así quedará", "Sync ventas\\nJueves 8 de octubre · 15:00–15:30\\nGoogle Meet\\nCon Pablo Gil")');

    $this->actingAs($this->user)->postJson(route('actions.confirm', $proposal->json('output.pending_action_id')))->assertOk();

    expect($this->writer->changes[0][0])->toBe('update')
        ->and($this->writer->changes[0][1])->toMatchArray([
            'ref' => 'demo:primary:20261008-3',
            'start' => '2026-10-08T15:00:00+02:00',
            'end' => '2026-10-08T15:30:00+02:00',
            'title' => 'Sync ventas',
            'remove_attendees' => ['lucia@covermanager.test'],
            'notify' => false,
        ]);
});

test('a change needs something to change, and someone who is invited', function () {
    runCalendarTool('propose_event_update', ['event_id' => $this->organised])->assertJsonPath('output.error', 'No has indicado ningún cambio para la reunión.');
    runCalendarTool('propose_event_update', ['event_id' => $this->organised, 'remove_people' => ['Andrés']])
        ->assertJsonPath('output.error', '«Andrés» no está invitado a «1:1 con Marta».');
    runCalendarTool('propose_event_update', ['event_id' => $this->organised, 'start_time' => '12:00', 'end_time' => '11:00'])
        ->assertJsonPath('output.error', 'La hora de fin tiene que ser posterior a la de inicio.');
});

test('meetings organised by someone else can not be moved', function () {
    runCalendarTool('propose_event_update', ['event_id' => $this->invited, 'start_time' => '10:00'])
        ->assertJsonPath('canvas', null)
        ->assertJsonPath('output.error', fn (string $error) => str_contains($error, 'no organiza'));
});
