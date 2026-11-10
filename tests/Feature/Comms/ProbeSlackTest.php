<?php

use App\Comms\Events\EventType;
use App\Comms\Ingestion\IdentityResolver;
use App\Comms\Models\CompletesTasks;
use App\Comms\Models\FakeCompleter;
use App\Comms\Models\ModelCompletion;
use App\Connections\Provider;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Config::set('comms.pipeline.slack', true);
});

function probeUser(): User
{
    $user = User::factory()->create();

    ConnectedAccount::factory()->for($user)->create([
        'provider' => Provider::Slack,
        'email' => 'me@example.com',
        'name' => 'Nicolás',
        'metadata' => ['user_id' => 'U-me'],
    ]);
    app(IdentityResolver::class)->ensureSelf($user);

    return $user;
}

/**
 * @param  list<array<string, mixed>>  $bodies
 */
function probeScript(array $bodies): FakeCompleter
{
    $fake = new FakeCompleter;
    $fake->script = array_map(fn (array $body): ModelCompletion => new ModelCompletion($body, 'scripted'), $bodies);
    app()->instance(CompletesTasks::class, $fake);

    return $fake;
}

test('a fake direct message runs the live pipeline and the report shows each step', function () {
    $user = probeUser();
    $models = probeScript([
        ['label' => 'possible_intent', 'urgent' => false, 'sensitive' => false, 'confidence' => 0.9],
        ['operations' => [[
            'op' => 'open',
            'what' => 'Revisar el PR de pagos',
            'quote' => '¿Me revisas el PR de pagos?',
            'confidence' => 0.9,
            'requesters' => ['slack:probe-marta-prueba'],
            'responders' => ['self'],
        ]], 'confidence' => 0.9],
    ]);

    $this->artisan('comms:probe-slack', ['user' => $user->id, 'text' => '¿Me revisas el PR de pagos?', '--from' => 'Marta Prueba', '--wait' => 1])
        ->expectsOutputToContain('Marta Prueba')
        ->expectsOutputToContain('model triage')
        ->expectsOutputToContain('message.flagged')
        ->expectsOutputToContain('Revisar el PR de pagos')
        ->assertSuccessful();

    $event = CommsEvent::query()->where('type', EventType::MessageReceived)->sole();

    expect($event->external_id)->toStartWith('probe:probe-dm:')
        ->and($event->normalized['backfill'])->toBeFalse()
        ->and($event->processed_at)->not->toBeNull()
        ->and(CommsIntent::query()->sole()->what)->toBe('Revisar el PR de pagos')
        ->and($models->calls[1]['input'])->toContain('«slack:probe-marta-prueba» es quien escribe el mensaje, «self» es la persona usuaria');
});

test('a past message is sent again as a new message and the original is left alone', function () {
    $user = probeUser();
    probeScript([
        ['label' => 'noise', 'urgent' => false, 'sensitive' => false, 'confidence' => 0.9],
        ['label' => 'noise', 'urgent' => false, 'sensitive' => false, 'confidence' => 0.9],
    ]);

    $this->artisan('comms:probe-slack', ['user' => $user->id, 'text' => 'Hola', '--wait' => 1])->assertSuccessful();
    $original = CommsEvent::query()->where('type', EventType::MessageReceived)->sole();

    $this->artisan('comms:probe-slack', ['user' => $user->id, '--rerun' => 1, '--wait' => 1])
        ->expectsOutputToContain('Hola')
        ->assertSuccessful();

    $copy = CommsEvent::query()->where('type', EventType::MessageReceived)->whereKeyNot($original->id)->sole();

    expect($copy->external_id)->toStartWith($original->external_id.':rerun-')
        ->and($copy->normalized['rerun_of'])->toBe($original->id)
        ->and($copy->normalized['text'])->toBe('Hola')
        ->and($copy->processed_at)->not->toBeNull()
        ->and($original->fresh()->external_id)->toBe($original->external_id);
});

test('without a text or a message to send again it says what to pass', function () {
    $user = probeUser();

    $this->artisan('comms:probe-slack', ['user' => $user->id])
        ->expectsOutputToContain('pass a text, --rerun=N or --event=ID')
        ->assertFailed();
});
