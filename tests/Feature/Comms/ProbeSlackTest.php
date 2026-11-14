<?php

use App\Alerts\Push\PushSender;
use App\Comms\Cutover\HomeFeed;
use App\Comms\Events\EventType;
use App\Comms\Gate\ActionClass;
use App\Comms\Gate\Gate;
use App\Comms\Ingestion\IdentityResolver;
use App\Comms\Ingestion\Ingestor;
use App\Comms\Ingestion\SlackInbound;
use App\Comms\Messages\Party;
use App\Comms\Models\CompletesTasks;
use App\Comms\Models\FakeCompleter;
use App\Comms\Models\ModelCompletion;
use App\Comms\Playbooks\Steps\Ask;
use App\Comms\Playbooks\Steps\Propose;
use App\Connections\Provider;
use App\Events\CommsNotificationDelivered;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsNotification;
use App\Models\ConnectedAccount;
use App\Models\PushSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\RecordingPushSender;

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

test('an urgent ask reaches the open screen at once, saying who asks and what', function () {
    Event::fake([CommsNotificationDelivered::class]);
    $user = probeUser();
    probeScript([
        ['label' => 'possible_intent', 'urgent' => true, 'sensitive' => false, 'confidence' => 0.9],
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
        ->expectsOutputToContain('alert')
        ->assertSuccessful();

    Event::assertDispatched(CommsNotificationDelivered::class, function (CommsNotificationDelivered $event) use ($user): bool {
        $card = $event->broadcastWith();

        return $event->broadcastAs() === 'inbox.item.created'
            && $event->broadcastOn()[0]->name === 'private-App.Models.User.'.$user->id
            && $card['title'] === 'Revisar el PR de pagos'
            && $card['why'] === 'Urgente. Espera tu respuesta.'
            && $card['actor_name'] === 'Marta Prueba'
            && $card['status'] === 'pending'
            && $card['state'] === 'important'
            && $card['messages'][0]['from'] === 'Marta Prueba'
            && $card['messages'][0]['text'] === '¿Me revisas el PR de pagos?';
    });
});

test('a past message is sent again as a new message and the original is left alone', function () {
    $user = probeUser();
    probeScript(array_fill(0, 3, ['label' => 'noise', 'urgent' => false, 'sensitive' => false, 'confidence' => 0.9]));

    $original = app(Ingestor::class)->ingest($user, new SlackInbound(
        teamId: 'T1',
        channelId: 'D1',
        ts: '1762770000.000100',
        channelKind: 'dm',
        author: new Party('U-marta', 'marta@example.com', 'Marta'),
        recipients: [],
        text: 'Hola',
        at: CarbonImmutable::now()->subHour(),
    ));
    $this->artisan('comms:probe-slack', ['user' => $user->id, 'text' => 'Esto es una prueba', '--wait' => 1])->assertSuccessful();

    $this->artisan('comms:probe-slack', ['user' => $user->id, '--rerun' => 1, '--wait' => 1])
        ->expectsOutputToContain('Hola')
        ->assertSuccessful();

    $copy = CommsEvent::query()->where('type', EventType::MessageReceived)->where('external_id', 'like', '%:rerun-%')->sole();

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

test('opening a comms alert shows the ask, its messages and the proposal waiting for a yes', function () {
    $user = probeUser();
    $models = probeScript([
        ['label' => 'possible_intent', 'urgent' => true, 'sensitive' => false, 'confidence' => 0.9],
        ['operations' => [[
            'op' => 'open',
            'what' => 'Revisar el PR de pagos',
            'quote' => '¿Me revisas el PR de pagos?',
            'confidence' => 0.9,
            'requesters' => ['slack:probe-marta-prueba'],
            'responders' => ['self'],
        ]], 'confidence' => 0.9],
    ]);
    $this->artisan('comms:probe-slack', ['user' => $user->id, 'text' => '¿Me revisas el PR de pagos?', '--from' => 'Marta Prueba', '--wait' => 1])->assertSuccessful();

    $intent = CommsIntent::query()->sole();
    $message = CommsMessage::query()->sole();
    $proposal = app(Propose::class)->submit($user, $intent, $message, 'Lo miro esta tarde.', []);
    $notification = CommsNotification::query()->whereBelongsTo($user)->where('subject_type', 'proposal')->where('subject_id', $proposal->id)->firstOrFail();

    $response = $this->actingAs($user)
        ->postJson(route('voice.tools.run', 'review_alert'), ['arguments' => ['alert_id' => -$notification->id], 'timezone' => 'Europe/Madrid'])
        ->assertOk()
        ->assertJsonPath('canvas.view', 'comms.review')
        ->assertJsonPath('canvas.props.what', 'Revisar el PR de pagos')
        ->assertJsonPath('canvas.props.requester', 'Marta Prueba')
        ->assertJsonPath('canvas.props.messages.0.text', '¿Me revisas el PR de pagos?')
        ->assertJsonPath('canvas.props.proposal.id', $proposal->id)
        ->assertJsonPath('canvas.props.proposal.text', 'Lo miro esta tarde.')
        ->assertJsonPath('output.proposal.text', 'Lo miro esta tarde.');

    expect($response->json('canvas.props.alert.why'))->toBe('Urgente. Espera tu respuesta.')
        ->and($response->json('output.note'))->toContain('propuesta')
        ->and($notification->fresh()->seen_at)->not->toBeNull()
        ->and($models->calls[1]['input'])->toContain('prepare si le piden hacer o preparar un trabajo');

    $this->actingAs($user)->postJson(route('comms.proposals.edit', $proposal), ['text' => 'Lo miro a las 16h.'])
        ->assertOk()
        ->assertJson(['ok' => true]);
});

test('an urgent comms alert reaches the device saying what the card says', function () {
    $sender = new RecordingPushSender;
    app()->instance(PushSender::class, $sender);
    $user = probeUser();
    PushSubscription::factory()->for($user)->create();
    probeScript([
        ['label' => 'possible_intent', 'urgent' => true, 'sensitive' => false, 'confidence' => 0.9],
        ['operations' => [[
            'op' => 'open',
            'what' => 'Revisar el PR de pagos',
            'quote' => '¿Me revisas el PR de pagos?',
            'confidence' => 0.9,
            'requesters' => ['slack:probe-marta-prueba'],
            'responders' => ['self'],
        ]], 'confidence' => 0.9],
    ]);

    $this->artisan('comms:probe-slack', ['user' => $user->id, 'text' => '¿Me revisas el PR de pagos?', '--from' => 'Marta Prueba', '--wait' => 1])->assertSuccessful();

    $notification = CommsNotification::query()->whereBelongsTo($user)->where('subject_type', 'intent')->where('urgency', 'now')->firstOrFail();
    $card = app(HomeFeed::class)->card($notification);
    $pushed = collect($sender->sent)->map(fn (array $sent): array => json_decode($sent['payload'], true)['notification']);

    expect($pushed)->not->toBeEmpty()
        ->and($pushed->first()['title'])->toBe('Marta Prueba · Slack')
        ->and($pushed->first()['body'])->toBe("{$card['title']}\n{$card['why']}")
        ->and($pushed->first()['navigate'])->toBe(url('/?alert='.$card['id']));
});

test('question and proposal notifications keep the requester and ask on the card and in web push', function () {
    $sender = new RecordingPushSender;
    app()->instance(PushSender::class, $sender);
    $user = probeUser();
    PushSubscription::factory()->for($user)->create();
    probeScript([
        ['label' => 'possible_intent', 'urgent' => false, 'sensitive' => false, 'confidence' => 0.9],
        ['operations' => [[
            'op' => 'open',
            'what' => 'Responder a Naiman sobre el tema pixel meta',
            'quote' => 'necesitamos contestar algo al de naiman sobre tema pixel meta',
            'confidence' => 0.9,
            'requesters' => ['slack:probe-patricia-corral'],
            'responders' => ['self'],
        ]], 'confidence' => 0.9],
    ]);

    $this->artisan(
        'comms:probe-slack',
        ['user' => $user->id, 'text' => 'cuando puedas necesitamos contestar algo al de naiman sobre tema pixel meta', '--from' => 'Patricia Corral', '--wait' => 1],
    )->assertSuccessful();

    $intent = CommsIntent::query()->sole();
    $message = CommsMessage::query()->sole();

    app(Ask::class)->open($user, $intent, 'Falta concretar el encargo.', ['draft' => ''], [$message->event_id], 'now');

    $questionNotification = CommsNotification::query()->whereBelongsTo($user)->where('subject_type', 'question')->sole();
    $questionCard = app(HomeFeed::class)->card($questionNotification);
    $pushed = collect($sender->sent)->map(fn (array $sent): array => json_decode($sent['payload'], true)['notification']);

    expect($questionCard['actor_name'])->toBe('Patricia Corral')
        ->and($questionCard['title'])->toBe('Responder a Naiman sobre el tema pixel meta')
        ->and($questionCard['why'])->toBe('Espera tu respuesta.')
        ->and($questionCard['messages'][0]['from'])->toBe('Patricia Corral')
        ->and($pushed->last()['title'])->toBe('Patricia Corral · Slack')
        ->and($pushed->last()['body'])->toBe("Responder a Naiman sobre el tema pixel meta\nEspera tu respuesta.");

    app(Propose::class)->submit($user, $intent, $message, 'Lo miro y te escribo cuando esté.', [], facts: false);
    app(Gate::class)->submit(
        user: $user,
        class: ActionClass::NotionCreatePage,
        target: ['ref' => 'self', 'channel' => (string) $message->channel_ref],
        payload: [
            'title' => $intent->what,
            'text' => 'La afirmacion se basa en el contexto proporcionado [fact:613, fact:644].',
            'ref' => 'notion:'.$intent->key,
        ],
        intent: $intent,
        sourceEventIds: [$message->event_id],
    );

    $proposalCards = CommsNotification::query()
        ->whereBelongsTo($user)
        ->where('subject_type', 'proposal')
        ->orderBy('id')
        ->get()
        ->map(fn (CommsNotification $row): array => app(HomeFeed::class)->card($row));

    expect($proposalCards)->toHaveCount(2)
        ->and($proposalCards[0]['actor_name'])->toBe('Patricia Corral')
        ->and($proposalCards[0]['title'])->toBe('Responder a Naiman sobre el tema pixel meta')
        ->and($proposalCards[0]['proposal']['summary'])->toBe('Lo miro y te escribo cuando esté.')
        ->and($proposalCards[1]['actor_name'])->toBe('Patricia Corral')
        ->and($proposalCards[1]['title'])->toBe('Responder a Naiman sobre el tema pixel meta')
        ->and($proposalCards[1]['proposal']['summary'])->toBe('Página en Notion: Responder a Naiman sobre el tema pixel meta');
});

test('a signal notification without an intent shows the sender and signal summary', function () {
    $user = probeUser();
    probeScript([
        ['label' => 'possible_signal', 'urgent' => false, 'sensitive' => false, 'confidence' => 0.9],
        ['operations' => [[
            'op' => 'signal',
            'quote' => 'Verdict: hard blockers, do not close.',
            'summary' => 'CPD-7205 tiene bloqueos pendientes antes de cerrar.',
            'confidence' => 0.85,
        ]], 'confidence' => 0.85],
    ]);

    $this->artisan(
        'comms:probe-slack',
        ['user' => $user->id, 'text' => 'Close-out check. Verdict: hard blockers, do not close.', '--from' => 'David Montiel', '--wait' => 1],
    )->assertSuccessful();

    $notification = CommsNotification::query()->whereBelongsTo($user)->where('kind', 'signal')->sole();
    $card = app(HomeFeed::class)->card($notification);

    expect($card['actor_name'])->toBe('David Montiel')
        ->and($card['title'])->toBe('CPD-7205 tiene bloqueos pendientes antes de cerrar.')
        ->and($card['why'])->toBe('Para el resumen.')
        ->and($card['messages'][0]['from'])->toBe('David Montiel');
});
