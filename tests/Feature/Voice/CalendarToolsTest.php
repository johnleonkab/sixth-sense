<?php

use App\Connectors\Calendar\CalendarReader;
use App\Connectors\Calendar\FakeCalendarReader;
use App\Models\User;
use App\Voice\Providers\FakeVoiceProvider;
use App\Voice\Providers\GeminiVoiceProvider;
use App\Voice\Tools\Toolbox;
use App\Voice\VoiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 08:00:00', 'UTC'));
    $this->app->instance(CalendarReader::class, $this->calendar = new FakeCalendarReader);
    $this->user = User::factory()->create();
});

function runVoiceTool(string $tool, array $arguments): TestResponse
{
    return test()->actingAs(test()->user)->postJson(route('voice.tools.run', $tool), [
        'arguments' => $arguments,
        'timezone' => 'Europe/Madrid',
    ]);
}

test('guests can not run tools', function () {
    $this->postJson(route('voice.tools.run', 'show_calendar'), ['arguments' => []])->assertUnauthorized();
});

test('unknown tools are not found', function () {
    runVoiceTool('delete_everything', [])->assertNotFound();
});

test('finding events answers the assistant without showing anything', function () {
    runVoiceTool('find_calendar_events', ['start_date' => '2026-10-07', 'attendee' => 'marta'])
        ->assertOk()
        ->assertJsonPath('canvas', null)
        ->assertJsonPath('output.total_events', 3)
        ->assertJsonPath('output.filters', ['attendee' => 'marta'])
        ->assertJsonPath('output.events.0', [
            'title' => 'Daily de producto',
            'date' => '2026-10-07',
            'weekday' => 'miércoles',
            'start' => '09:30',
            'end' => '09:45',
            'attendees' => ['Marta Ruiz', 'Pablo Gil'],
            'event_id' => 'demo:primary:20261007-0',
            'my_response' => 'needsAction',
        ]);

    expect($this->calendar->queries[0]->timezone())->toBe('Europe/Madrid');
});

test('showing a single day draws the day timeline', function () {
    runVoiceTool('show_calendar', ['start_date' => '2026-10-07'])
        ->assertOk()
        ->assertJsonPath('output.shown_on_screen', true)
        ->assertJsonPath('output.total_events', 5)
        ->assertJsonPath('canvas.view', 'calendar.day')
        ->assertJsonPath('canvas.props.title', 'Hoy, 7 de octubre')
        ->assertJsonPath('canvas.props.timezone', 'Europe/Madrid')
        ->assertJsonPath('canvas.props.events.0.start', '2026-10-07T09:30:00+02:00');
});

test('showing a range or a search draws the agenda', function (array $arguments, string $title) {
    runVoiceTool('show_calendar', $arguments)
        ->assertJsonPath('canvas.view', 'calendar.agenda')
        ->assertJsonPath('canvas.props.title', $title);
})->with([
    'a week' => [['start_date' => '2026-10-05', 'end_date' => '2026-10-11'], 'Del 5 al 11 de octubre'],
    'a search' => [['start_date' => '2026-10-08', 'title' => 'daily'], 'Mañana, 8 de octubre'],
]);

test('invalid arguments are returned to the assistant as an error', function (array $arguments) {
    runVoiceTool('show_calendar', $arguments)
        ->assertOk()
        ->assertJsonPath('canvas', null)
        ->assertJsonStructure(['output' => ['error']]);
})->with([
    'missing date' => [[]],
    'wrong format' => [['start_date' => '07/10/2026']],
    'end before start' => [['start_date' => '2026-10-07', 'end_date' => '2026-10-01']],
    'too long' => [['start_date' => '2026-01-01', 'end_date' => '2026-12-31']],
]);

test('the voice session declares the tools to the provider', function () {
    $this->app->instance(VoiceProvider::class, $voice = new FakeVoiceProvider);

    $this->actingAs($this->user)->postJson(route('voice.sessions.store'))->assertCreated();

    expect(array_column($voice->started[0]['assistant']->tools, 'name'))->toContain('find_calendar_events', 'show_calendar');
});

test('gemini locks the tools into the session with its schema format', function () {
    Http::fake(['*' => Http::response(['name' => 'auth_tokens/abc'])]);
    config(['voice.providers.gemini.api_key' => 'test-key']);
    $this->app->forgetInstance(VoiceProvider::class);
    $this->app->instance(VoiceProvider::class, new GeminiVoiceProvider(config('voice.providers.gemini'), config('voice.session')));

    $grant = $this->actingAs($this->user)->postJson(route('voice.sessions.store'))->assertCreated();

    Http::assertSent(function (Request $request) {
        $declarations = collect($request['bidiGenerateContentSetup']['tools'][0]['functionDeclarations'])->keyBy('name');
        $calendar = $declarations['show_calendar']['parameters'];
        $mail = $declarations['show_email_results']['parameters']['properties'];

        return $calendar['type'] === 'OBJECT'
            && $calendar['properties']['start_date']['type'] === 'STRING'
            && $calendar['required'] === ['start_date']
            && $mail['has_attachment']['type'] === 'BOOLEAN'
            && $mail['limit']['type'] === 'INTEGER'
            && $mail['folder']['enum'] === ['any', 'inbox', 'sent'];
    });

    expect($grant->json('connection.tools.0.functionDeclarations'))->toHaveCount(count(app(Toolbox::class)->all()));
});
