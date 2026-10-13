<?php

use App\Connectors\Calendar\CalendarReader;
use App\Connectors\Calendar\FakeCalendarReader;
use App\Events\WingmanMeetingEnded;
use App\Events\WingmanMeetingStarted;
use App\Jobs\PrepareWingmanContext;
use App\Models\User;
use App\Models\WingmanMeeting;
use App\Wingman\ExtensionTokens;
use App\Wingman\MeetingStatus;
use App\Wingman\WingmanMeetings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:58', 'Europe/Madrid'));
    config(['agenda.timezone' => 'Europe/Madrid']);
    $this->app->instance(CalendarReader::class, new FakeCalendarReader);
    Queue::fake();
    Event::fake([WingmanMeetingStarted::class, WingmanMeetingEnded::class]);
    $this->user = User::factory()->create(['wingman_enabled' => true]);
    $this->token = app(ExtensionTokens::class)->issue($this->user);
});

function extension(string $method, string $uri, array $data = [], ?string $token = null)
{
    return test()->withToken($token ?? test()->token)->json($method, "/api/wingman/extension/{$uri}", $data);
}

function segment(string $id, string $speaker, string $text, bool $final = false): array
{
    return ['client_id' => $id, 'speaker' => $speaker, 'text' => $text, 'final' => $final, 'spoken_at' => now()->toIso8601String()];
}

test('only a valid token of someone with wingman on gets in', function () {
    extension('GET', 'ping', token: 'wm_nope')->assertUnauthorized();
    $this->withHeaders([])->getJson('/api/wingman/extension/ping')->assertUnauthorized();

    extension('GET', 'ping')->assertOk()->assertJson(['user' => $this->user->name]);
    expect($this->user->refresh()->wingman_token_used_at)->not->toBeNull();

    $this->user->update(['wingman_enabled' => false]);
    extension('GET', 'ping')->assertForbidden();
});

test('a new token replaces the old one, and revoking it shuts the extension out', function () {
    $new = app(ExtensionTokens::class)->issue($this->user);

    extension('GET', 'ping')->assertUnauthorized();
    extension('GET', 'ping', token: $new)->assertOk();

    app(ExtensionTokens::class)->revoke($this->user);
    extension('GET', 'ping', token: $new)->assertUnauthorized();
});

test('joining a call finds its meeting in the calendar and marks it going on', function () {
    app(WingmanMeetings::class)->sync($this->user);
    Queue::fake();

    $meetingId = extension('POST', 'calls', ['code' => 'pre-pago-rev', 'joined_at' => now()->toIso8601String()])
        ->assertOk()
        ->assertJsonPath('title', 'Revisión flujo de prepago')
        ->json('meeting_id');

    $meeting = WingmanMeeting::find($meetingId);

    expect($meeting->status)->toBe(MeetingStatus::Live)
        ->and($meeting->joined_at)->not->toBeNull()
        ->and($this->user->wingmanMeetings()->count())->toBe(4);
    Queue::assertPushed(PrepareWingmanContext::class, fn (PrepareWingmanContext $job) => $job->meeting->is($meeting));
    Event::assertDispatched(WingmanMeetingStarted::class, fn (WingmanMeetingStarted $event) => $event->meeting->is($meeting));
});

test('a call in no calendar is followed too, and joining again keeps the first time in', function () {
    $first = extension('POST', 'calls', ['code' => 'zzz-zzzz-zzz', 'joined_at' => now()->toIso8601String()])->assertOk()->json('meeting_id');
    $meeting = WingmanMeeting::find($first);

    expect($meeting->title)->toBe('Llamada de Meet (zzz-zzzz-zzz)')
        ->and($meeting->meeting_url)->toBe('https://meet.google.com/zzz-zzzz-zzz');

    $this->travel(5)->minutes();
    $again = extension('POST', 'calls', ['code' => 'zzz-zzzz-zzz', 'joined_at' => now()->toIso8601String()])->json('meeting_id');

    expect($again)->toBe($first)
        ->and($meeting->refresh()->joined_at->lt(now()->subMinutes(4)))->toBeTrue();
});

test('what is said is kept by segment: it grows as it is sent again, and the person is told apart', function () {
    $meeting = WingmanMeeting::factory()->for($this->user)->create(['status' => MeetingStatus::Live]);

    extension('POST', "calls/{$meeting->id}/segments", ['segments' => [segment('t#1', 'Marta Ruiz', '¿Cómo va')]])->assertOk()->assertJson(['taken' => 1]);
    extension('POST', "calls/{$meeting->id}/segments", ['segments' => [
        segment('t#1', 'Marta Ruiz', '¿Cómo va el reembolso parcial?', final: true),
        segment('t#2', 'Tú', 'Lo miro en Jira'),
    ]])->assertOk();

    $segments = $meeting->segments()->get();

    expect($segments)->toHaveCount(2)
        ->and($segments[0]->text)->toBe('¿Cómo va el reembolso parcial?')
        ->and($segments[0]->final)->toBeTrue()
        ->and($segments[0]->is_me)->toBeFalse()
        ->and($segments[1]->is_me)->toBeTrue();
});

test('segments are checked, and only go to meetings of the token owner', function () {
    $meeting = WingmanMeeting::factory()->for($this->user)->create();
    $other = WingmanMeeting::factory()->create();

    extension('POST', "calls/{$meeting->id}/segments", ['segments' => [['speaker' => 'X']]])->assertUnprocessable();
    extension('POST', "calls/{$other->id}/segments", ['segments' => [segment('a', 'X', 'hola')]])->assertNotFound();
    extension('POST', 'calls', ['code' => 'not-a-code', 'joined_at' => now()->toIso8601String()])->assertUnprocessable();
});

test('leaving ends the call and closes what was left open', function () {
    $meeting = WingmanMeeting::factory()->for($this->user)->create(['status' => MeetingStatus::Live, 'joined_at' => now()]);
    extension('POST', "calls/{$meeting->id}/segments", ['segments' => [segment('t#1', 'Marta Ruiz', 'Adiós')]]);

    extension('POST', "calls/{$meeting->id}/end", ['left_at' => now()->toIso8601String()])->assertOk();

    expect($meeting->refresh()->status)->toBe(MeetingStatus::Ended)
        ->and($meeting->left_at)->not->toBeNull()
        ->and($meeting->segments()->first()->final)->toBeTrue();
    Event::assertDispatched(WingmanMeetingEnded::class);
});

test('a call the extension stopped telling about is taken as over', function () {
    $meeting = WingmanMeeting::factory()->for($this->user)->create(['status' => MeetingStatus::Live, 'starts_at' => now()->subHour(), 'ends_at' => now()]);
    $meeting->forceFill(['updated_at' => now()->subMinutes(20)])->saveQuietly();

    app(WingmanMeetings::class)->sync($this->user);

    expect($meeting->refresh()->status)->toBe(MeetingStatus::Ended)
        ->and($meeting->left_at)->not->toBeNull();
});
