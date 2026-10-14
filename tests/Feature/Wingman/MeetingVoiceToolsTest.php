<?php

use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Models\User;
use App\Models\WingmanAction;
use App\Models\WingmanMeeting;
use App\Wingman\MeetingStatus;

beforeEach(function () {
    $this->app->instance(Chats::class, new FakeChats);
    $this->user = User::factory()->create(['wingman_enabled' => true]);
    $this->meeting = WingmanMeeting::factory()->for($this->user)->create([
        'title' => 'Revisión flujo de prepago',
        'status' => MeetingStatus::Ended,
        'joined_at' => now()->subHour(),
        'closed_at' => now(),
        'notes' => ['summary' => 'El reembolso parcial pasa al siguiente sprint.', 'topics' => [], 'decisions' => ['Solo pago completo en la demo.'], 'questions' => [], 'commitments' => []],
    ]);
    $this->first = WingmanAction::factory()->for($this->meeting, 'meeting')->create(['title' => 'Escribir a Marta']);
    $this->second = WingmanAction::factory()->for($this->meeting, 'meeting')->create(['title' => 'Convocar el seguimiento', 'kind' => 'meeting']);
});

function meetingTool(string $name, array $arguments = [])
{
    return test()->actingAs(test()->user)->postJson("/voice/tools/{$name}", ['arguments' => $arguments, 'timezone' => 'Europe/Madrid']);
}

test('"repásame lo de la reunión" shows the latest one, with what is left to do numbered', function () {
    WingmanMeeting::factory()->for($this->user)->create(['title' => 'Una sin seguir']);

    meetingTool('review_meeting')
        ->assertOk()
        ->assertJsonPath('output.title', 'Revisión flujo de prepago')
        ->assertJsonPath('output.summary', 'El reembolso parcial pasa al siguiente sprint.')
        ->assertJsonPath('output.to_do.1', ['number' => 2, 'what' => 'Convocar el seguimiento', 'state' => 'proposed'])
        ->assertJsonPath('canvas.view', 'wingman.doc')
        ->assertJsonPath('canvas.props.actions.0.number', 1);

    meetingTool('review_meeting', ['title' => 'prepago'])->assertJsonPath('output.meeting_id', $this->meeting->id);
    meetingTool('review_meeting', ['title' => 'nada que ver'])->assertJsonPath('output.error', fn (string $error) => str_contains($error, 'No encuentro'));
});

test('"ejecuta la 2" prepares it for the "sí", without doing it', function () {
    meetingTool('propose_meeting_action', ['number' => 2])
        ->assertOk()
        ->assertJsonPath('output.status', 'esperando confirmación')
        ->assertJsonPath('output.what', 'Convocar el seguimiento')
        ->assertJsonPath('canvas.props.highlight', 2)
        ->assertJsonPath('canvas.props.action.status', 'pending');

    expect($this->second->refresh()->state())->toBe('reviewing');
    meetingTool('propose_meeting_action', ['number' => 9])->assertJsonPath('output.error', fn (string $error) => str_contains($error, 'ese número'));
});

test('"descarta las demás" drops what is still only proposed', function () {
    meetingTool('propose_meeting_action', ['number' => 2]);

    meetingTool('dismiss_meeting_actions', ['all' => true])->assertOk()->assertJsonPath('output.dismissed', ['Escribir a Marta']);

    expect($this->first->refresh()->state())->toBe('dismissed')
        ->and($this->second->refresh()->state())->toBe('reviewing');
});
