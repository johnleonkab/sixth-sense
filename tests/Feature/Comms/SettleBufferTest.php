<?php

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\MessageProjector;
use App\Comms\Events\Replay;
use App\Comms\Events\Source;
use App\Comms\Intents\Archive;
use App\Comms\Messages\Direction;
use App\Comms\Messages\Message;
use App\Comms\Messages\Party;
use App\Comms\Windows\SettleBuffer;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsWindow;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

afterEach(function () {
    Config::set('comms.linking.v2', false);
    CarbonImmutable::setTestNow();
});

test('the buffer stays idle until linking v2 is on', function () {
    $user = User::factory()->create();
    $author = CommsPerson::factory()->for($user)->create(['importance_weight' => 10]);

    app(SettleBuffer::class)->add(bufferMessage($user, $author, 'D1', 'Hola', '2026-11-02T10:00:00Z'));

    expect(CommsWindow::query()->count())->toBe(0);
});

test('eight direct messages in three minutes share one window, and two quiet minutes settles it', function () {
    Config::set('comms.linking.v2', true);
    $user = User::factory()->create();
    $author = CommsPerson::factory()->for($user)->create(['importance_weight' => 10]);
    $buffer = app(SettleBuffer::class);
    $start = CarbonImmutable::parse('2026-11-02T10:00:00Z');

    $messages = [];

    for ($i = 0; $i < 8; $i++) {
        $messages[] = bufferMessage($user, $author, 'D1', "Nota {$i}", $start->addSeconds($i * 25));
        $buffer->add($messages[$i]);
    }

    $buffer->add($messages[0]);

    $window = CommsWindow::query()->whereBelongsTo($user)->sole();

    expect($window->kind)->toBe('dm')
        ->and($window->group_ref)->toBe('D1')
        ->and($window->state)->toBe('open')
        ->and($window->message_ids)->toEqual(array_map(fn (CommsMessage $message): int => $message->id, $messages))
        ->and($buffer->due($user, $start->addSeconds(7 * 25)->addMinute()))->toBe(0)
        ->and($window->refresh()->state)->toBe('open')
        ->and($buffer->due($user, $start->addSeconds(7 * 25)->addMinutes(2)))->toBe(1)
        ->and($window->refresh()->state)->toBe('settled');

    $event = CommsEvent::query()->whereBelongsTo($user)->where('type', EventType::WindowSettled)->sole();

    expect($event->external_id)->toBe('window:'.$window->id)
        ->and($event->normalized['window_id'])->toBe($window->id)
        ->and($event->normalized['message_ids'])->toEqual($window->message_ids)
        ->and($event->normalized['lookback_ids'])->toEqual([]);
});

test('a channel settles after five quiet minutes', function () {
    Config::set('comms.linking.v2', true);
    $user = User::factory()->create();
    $author = CommsPerson::factory()->for($user)->create(['importance_weight' => 10]);
    $at = CarbonImmutable::parse('2026-11-02T10:00:00Z');

    app(SettleBuffer::class)->add(bufferMessage($user, $author, 'C1', 'El flujo', $at));

    CarbonImmutable::setTestNow($at->addMinutes(4));
    $this->artisan('comms:tick')->assertSuccessful();

    expect(CommsWindow::query()->whereBelongsTo($user)->sole()->state)->toBe('open');

    CarbonImmutable::setTestNow($at->addMinutes(5));
    $this->artisan('comms:tick')->assertSuccessful();

    $window = CommsWindow::query()->whereBelongsTo($user)->sole();

    expect($window->kind)->toBe('channel')
        ->and($window->state)->toBe('settled')
        ->and($window->settled_event_id)->not->toBeNull();
});

test('twenty minutes settles a channel even while messages are still arriving', function () {
    Config::set('comms.linking.v2', true);
    $user = User::factory()->create();
    $author = CommsPerson::factory()->for($user)->create(['importance_weight' => 10]);
    $buffer = app(SettleBuffer::class);
    $start = CarbonImmutable::parse('2026-11-02T10:00:00Z');

    foreach ([0, 10, 19] as $minute) {
        $buffer->add(bufferMessage($user, $author, 'C9', 'Sigue', $start->addMinutes($minute)));
    }

    expect($buffer->due($user, $start->addMinutes(19)))->toBe(0)
        ->and($buffer->due($user, $start->addMinutes(20)))->toBe(1)
        ->and(CommsWindow::query()->whereBelongsTo($user)->sole()->state)->toBe('settled')
        ->and(CommsWindow::query()->whereBelongsTo($user)->sole()->message_ids)->toHaveCount(3);
});

test('an urgent message settles the window at once', function () {
    Config::set('comms.linking.v2', true);
    $user = User::factory()->create();
    $author = CommsPerson::factory()->for($user)->create(['importance_weight' => 10]);
    app(Archive::class)->record($user, [
        'kind' => 'urgent_rule',
        'op' => 'set',
        'rule' => ['kind' => 'keyword', 'ref' => 'prepago', 'sources' => [], 'active' => true],
    ]);

    app(SettleBuffer::class)->add(bufferMessage($user, $author, 'C1', 'El prepago, hoy.', '2026-11-02T10:00:00Z'));

    expect(CommsWindow::query()->whereBelongsTo($user)->sole()->state)->toBe('settled')
        ->and(CommsEvent::query()->whereBelongsTo($user)->where('type', EventType::WindowSettled)->count())->toBe(1);
});

test('a thread uses the shorter quiet time', function () {
    Config::set('comms.linking.v2', true);
    $user = User::factory()->create();
    $author = CommsPerson::factory()->for($user)->create(['importance_weight' => 10]);
    $at = CarbonImmutable::parse('2026-11-02T10:00:00Z');
    $message = bufferMessage($user, $author, 'C1', 'En el hilo', $at);
    $message->thread_ref = '111.222';
    $message->save();
    $buffer = app(SettleBuffer::class);
    $buffer->add($message);

    $window = CommsWindow::query()->whereBelongsTo($user)->sole();

    expect($window->kind)->toBe('thread')
        ->and($window->group_ref)->toBe('111.222')
        ->and($buffer->due($user, $at->addMinute()))->toBe(0)
        ->and($buffer->due($user, $at->addMinutes(2)))->toBe(1);
});

test('replay rebuilds an open window from the log', function () {
    Config::set('comms.linking.v2', true);
    $user = User::factory()->create();
    $buffer = app(SettleBuffer::class);
    $start = CarbonImmutable::parse('2026-11-02T10:00:00Z');

    for ($i = 0; $i < 8; $i++) {
        $buffer->add(loggedMessage($user, 'D1', "Nota {$i}", $start->addSeconds($i * 25)));
    }

    $before = windowShape($user);

    app(Replay::class)->run($user);

    expect(windowShape($user))->toEqual($before)
        ->and($before[0]['state'])->toBe('open')
        ->and($before[0]['count'])->toBe(8)
        ->and($before[0]['texts'])->toBe(['Nota 0', 'Nota 1', 'Nota 2', 'Nota 3', 'Nota 4', 'Nota 5', 'Nota 6', 'Nota 7']);
});

test('an edit of a processed window opens a new window while it is still recent', function () {
    Config::set('comms.linking.v2', true);
    $user = User::factory()->create();
    $author = CommsPerson::factory()->for($user)->create(['importance_weight' => 10]);
    $at = CarbonImmutable::parse('2026-11-02T10:00:00Z');
    $message = bufferMessage($user, $author, 'C1', 'El flujo', $at);
    $buffer = app(SettleBuffer::class);
    $buffer->add($message);

    $window = CommsWindow::query()->whereBelongsTo($user)->sole();
    $window->state = 'processed';
    $window->save();

    $buffer->add($message, edited: true, at: $at->addHours(25));

    expect(CommsWindow::query()->whereBelongsTo($user)->count())->toBe(1);

    $buffer->add($message, edited: true, at: $at->addHour());

    $windows = CommsWindow::query()->whereBelongsTo($user)->orderBy('id')->get();

    expect($windows)->toHaveCount(2)
        ->and($windows[0]->state)->toBe('processed')
        ->and($windows[1]->state)->toBe('open')
        ->and($windows[1]->message_ids)->toEqual([$message->id]);
});

function bufferMessage(User $user, CommsPerson $author, string $channel, string $text, mixed $at): CommsMessage
{
    $at = CarbonImmutable::parse($at);

    return CommsMessage::factory()->create([
        'user_id' => $user->id,
        'author_person_id' => $author->id,
        'event_id' => CommsEvent::factory()->create([
            'user_id' => $user->id,
            'processed_at' => now(),
        ])->id,
        'source' => Source::Slack,
        'channel_ref' => $channel,
        'thread_ref' => null,
        'text' => $text,
        'direction' => 'in',
        'occurred_at' => $at,
        'external_id' => 'T:'.$channel.':'.$at->getTimestamp().fake()->unique()->numerify('####'),
    ]);
}

function loggedMessage(User $user, string $channel, string $text, CarbonImmutable $at): CommsMessage
{
    $externalId = 'T:'.$channel.':'.$at->getTimestamp().fake()->unique()->numerify('####');
    $event = app(EventLog::class)->append(
        user: $user,
        type: EventType::MessageReceived,
        source: Source::Slack,
        externalId: $externalId,
        payload: null,
        normalized: (new Message(
            externalId: $externalId,
            source: Source::Slack,
            channel: $channel,
            thread: null,
            author: new Party('U1', 'ana@example.com', 'Ana'),
            recipients: [],
            direction: Direction::Incoming,
            at: $at,
            text: $text,
        ))->toArray(),
        causation: null,
        occurredAt: $at,
        dispatch: false,
    );

    app(MessageProjector::class)->apply($event, audit: false);

    return CommsMessage::query()->where('event_id', $event->id)->firstOrFail();
}

/**
 * @return list<array{source: string, group_ref: string, kind: string, state: string, count: int, texts: list<string>}>
 */
function windowShape(User $user): array
{
    return CommsWindow::query()->whereBelongsTo($user)->orderBy('id')->get()->map(function (CommsWindow $window): array {
        $ids = $window->message_ids ?? [];

        return [
            'source' => $window->source,
            'group_ref' => $window->group_ref,
            'kind' => $window->kind,
            'state' => $window->state,
            'count' => count($ids),
            'texts' => CommsMessage::query()->whereIn('id', $ids)->orderBy('occurred_at')->orderBy('id')->pluck('text')->all(),
        ];
    })->all();
}
