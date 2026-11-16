<?php

use App\Comms\Events\EventType;
use App\Comms\Ingestion\FakeInboundFeed;
use App\Comms\Ingestion\IdentityResolver;
use App\Comms\Ingestion\InboundFeed;
use App\Comms\Ingestion\Ingestor;
use App\Comms\Ingestion\SlackInbound;
use App\Comms\Messages\Party;
use App\Comms\Models\CompletesTasks;
use App\Comms\Models\FakeCompleter;
use App\Comms\Models\ModelCompletion;
use App\Connections\Provider;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsNotification;
use App\Models\ConnectedAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

test('backfill reads the configured window when days is omitted', function () {
    $user = User::factory()->create();
    $feed = new FakeInboundFeed;
    $feed->items = [
        new SlackInbound('T1', 'D1', '20.1', 'dm', new Party('U1', 'a@example.com', 'Ana'), [], 'Inside', CarbonImmutable::now()->subDays(20)),
        new SlackInbound('T1', 'D1', '40.1', 'dm', new Party('U1', 'a@example.com', 'Ana'), [], 'Outside', CarbonImmutable::now()->subDays(40)),
    ];
    $this->app->instance(InboundFeed::class, $feed);

    expect(config('comms.backfill.days'))->toBe(30);

    $this->artisan('comms:backfill', ['user' => $user->id])->assertSuccessful();

    $texts = CommsEvent::query()
        ->whereIn('type', [EventType::MessageReceived, EventType::MessageSent])
        ->orderBy('id')
        ->get()
        ->map(fn (CommsEvent $event): string => $event->normalized['text'])
        ->all();

    expect($texts)->toBe(['Inside']);
});

test('backfill runs the passes in order and a second run appends nothing', function () {
    $user = User::factory()->create();
    $this->app->instance(InboundFeed::class, new FakeInboundFeed);

    $this->artisan('comms:backfill', ['user' => $user->id, '--days' => 30])->assertSuccessful();

    $passes = CommsEvent::query()
        ->where('type', EventType::BackfillCompleted)
        ->orderBy('id')
        ->get();
    $count = CommsEvent::query()->count();

    expect($passes->pluck('normalized.pass')->all())->toBe(['identity', 'labels', 'topics', 'patterns', 'sweep'])
        ->and($passes->every(fn (CommsEvent $event): bool => is_array($event->normalized['counts'])))->toBeTrue();

    $this->artisan('comms:backfill', ['user' => $user->id, '--passes' => 'sweep,identity'])->assertSuccessful();

    expect(CommsEvent::query()->count())->toBe($count)
        ->and(CommsEvent::query()->where('type', EventType::BackfillCompleted)->count())->toBe(5);
});

test('a chosen subset of passes still runs in the fixed order', function () {
    $user = User::factory()->create();
    $this->app->instance(InboundFeed::class, new FakeInboundFeed);

    $this->artisan('comms:backfill', ['user' => $user->id, '--passes' => 'sweep,identity'])->assertSuccessful();

    expect(CommsEvent::query()->where('type', EventType::BackfillCompleted)->orderBy('id')->get()->pluck('normalized.pass')->all())
        ->toBe(['identity', 'sweep']);
});

test('an unknown pass is rejected before anything is written', function () {
    $user = User::factory()->create();
    $this->app->instance(InboundFeed::class, new FakeInboundFeed);

    $this->artisan('comms:backfill', ['user' => $user->id, '--passes' => 'identity,nope'])->assertFailed();

    expect(CommsEvent::query()->count())->toBe(0);
});

test('a backfilled message that would alert now does not', function () {
    Config::set('comms.pipeline.slack', true);
    $live = User::factory()->create();
    $backfill = User::factory()->create();

    foreach ([$live, $backfill] as $user) {
        ConnectedAccount::factory()->for($user)->create([
            'provider' => Provider::Slack,
            'email' => 'me@example.com',
            'name' => 'Nicolás',
            'metadata' => ['user_id' => 'U-me'],
        ]);
        app(IdentityResolver::class)->ensureSelf($user);
    }
    $fake = new FakeCompleter;
    $fake->script = array_map(
        fn (array $body): ModelCompletion => new ModelCompletion($body, 'scripted'),
        [
            ['label' => 'possible_intent', 'urgent' => true, 'sensitive' => false, 'confidence' => 0.9],
            ['operations' => [[
                'op' => 'open',
                'what' => 'Revisar el PR de pagos',
                'quote' => '¿Me revisas el PR de pagos?',
                'confidence' => 0.9,
                'requesters' => ['slack:U-marta'],
                'responders' => ['self'],
            ]], 'confidence' => 0.9],
            ['label' => 'possible_intent', 'urgent' => true, 'sensitive' => false, 'confidence' => 0.9],
            ['operations' => [[
                'op' => 'open',
                'what' => 'Revisar el PR de pagos',
                'quote' => '¿Me revisas el PR de pagos?',
                'confidence' => 0.9,
                'requesters' => ['slack:U-marta'],
                'responders' => ['self'],
            ]], 'confidence' => 0.9],
        ],
    );
    $this->app->instance(CompletesTasks::class, $fake);

    $item = fn (): SlackInbound => new SlackInbound(
        'T1',
        'D1',
        '1.1',
        'dm',
        new Party('U-marta', 'marta@example.com', 'Marta Prueba'),
        [],
        '¿Me revisas el PR de pagos?',
        CarbonImmutable::now()->subHour(),
    );

    app(Ingestor::class)->ingest($live, $item());

    expect(CommsIntent::query()->whereBelongsTo($live)->count())->toBe(1)
        ->and(CommsNotification::query()->whereBelongsTo($live)->where('urgency', 'now')->count())->toBe(1);

    $feed = new FakeInboundFeed;
    $feed->items = [$item()];
    $this->app->instance(InboundFeed::class, $feed);

    $this->artisan('comms:backfill', ['user' => $backfill->id, '--days' => 30, '--passes' => 'identity'])->assertSuccessful();

    expect(CommsIntent::query()->whereBelongsTo($backfill)->count())->toBe(1)
        ->and(CommsNotification::query()->whereBelongsTo($backfill)->where('urgency', 'now')->count())->toBe(0);
});
