<?php

use App\Events\WingmanMeetingUpdated;
use App\Models\User;
use App\Models\WingmanMeeting;
use App\Models\WingmanSegment;
use App\Wingman\ExtensionTokens;
use App\Wingman\LiveCall;
use App\Wingman\MeetingStatus;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([WingmanMeetingUpdated::class]);
    $this->user = User::factory()->create(['wingman_enabled' => true]);
});

test('with no call going on, the bubble has nothing to show', function () {
    WingmanMeeting::factory()->for($this->user)->create(['status' => MeetingStatus::Ended]);

    $this->actingAs($this->user)->getJson(route('wingman.live'))->assertOk()->assertJson(['meeting' => null]);
});

test('in a call, the bubble shows it with the last things said and its context', function () {
    $meeting = WingmanMeeting::factory()->for($this->user)->create([
        'status' => MeetingStatus::Live,
        'joined_at' => now()->subMinutes(5),
        'context' => ['issues' => [['ref' => 'demo:CPD-412', 'key' => 'CPD-412', 'summary' => 'Total', 'status' => 'To Do', 'status_category' => 'new', 'url' => null, 'blocked_by' => []]], 'pages' => []],
        'context_prepared_at' => now(),
    ]);

    foreach (range(1, LiveCall::LINES + 2) as $index) {
        WingmanSegment::factory()->for($meeting, 'meeting')->create(['text' => "Frase {$index}", 'spoken_at' => now()->subMinutes(10)->addSeconds($index)]);
    }

    WingmanSegment::factory()->for($meeting, 'meeting')->create(['speaker' => 'Tú', 'is_me' => true, 'text' => 'La mía', 'spoken_at' => now()]);

    $this->actingAs($this->user)->getJson(route('wingman.live'))
        ->assertOk()
        ->assertJsonPath('meeting.id', $meeting->id)
        ->assertJsonPath('meeting.paused', false)
        ->assertJsonPath('meeting.segment_count', LiveCall::LINES + 3)
        ->assertJsonCount(LiveCall::LINES, 'meeting.lines')
        ->assertJsonPath('meeting.lines.'.(LiveCall::LINES - 1), fn (array $line) => $line['text'] === 'La mía' && $line['is_me'] && $line['speaker'] === 'Tú')
        ->assertJsonPath('meeting.issues.0.key', 'CPD-412');
});

test('pausing stops keeping what is said, until listening again', function () {
    $meeting = WingmanMeeting::factory()->for($this->user)->create(['status' => MeetingStatus::Live]);
    $token = app(ExtensionTokens::class)->issue($this->user);
    $send = fn (string $id) => $this->withToken($token)->postJson("/api/wingman/extension/calls/{$meeting->id}/segments", ['segments' => [['client_id' => $id, 'speaker' => 'Marta', 'text' => 'Hola', 'spoken_at' => now()->toIso8601String()]]]);

    $this->actingAs($this->user)->postJson(route('wingman.pause', $meeting->id), ['paused' => true])->assertOk()->assertJsonPath('meeting.paused', true);
    $send('a')->assertOk()->assertJson(['taken' => 0, 'paused' => true]);

    $this->actingAs($this->user)->postJson(route('wingman.pause', $meeting->id), ['paused' => false])->assertJsonPath('meeting.paused', false);
    $send('b')->assertOk()->assertJson(['taken' => 1, 'paused' => false]);

    expect($meeting->segments()->pluck('client_id')->all())->toBe(['b']);
    Event::assertDispatchedTimes(WingmanMeetingUpdated::class, 2);
});

test('only a call of the person, going on, can be paused', function () {
    $ended = WingmanMeeting::factory()->for($this->user)->create(['status' => MeetingStatus::Ended]);
    $others = WingmanMeeting::factory()->create(['status' => MeetingStatus::Live]);

    $this->actingAs($this->user)->postJson(route('wingman.pause', $ended->id), ['paused' => true])->assertNotFound();
    $this->actingAs($this->user)->postJson(route('wingman.pause', $others->id), ['paused' => true])->assertNotFound();
});

test("a meeting's page shows what was said, the person as Tú", function () {
    $meeting = WingmanMeeting::factory()->for($this->user)->create(['status' => MeetingStatus::Live, 'context' => [], 'context_prepared_at' => now()]);
    WingmanSegment::factory()->for($meeting, 'meeting')->create(['speaker' => 'John León', 'is_me' => true, 'text' => 'Lo miro']);

    $this->actingAs($this->user)->get(route('wingman.show', $meeting->id))
        ->assertInertia(fn ($page) => $page->where('meeting.status', 'live')->where('meeting.transcript.0.speaker', 'Tú')->where('meeting.transcript.0.text', 'Lo miro'));
});
