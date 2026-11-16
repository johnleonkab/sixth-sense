<?php

use App\Comms\Events\Source;
use App\Comms\Windows\WindowAssembler;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsWindow;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

afterEach(function () {
    Config::set('comms.window.lookback_messages', 30);
    Config::set('comms.window.lookback_hours', 48);
});

test('the assembler keeps earlier messages of the same conversation and the people who wrote them', function () {
    $user = User::factory()->create();
    $ana = CommsPerson::factory()->for($user)->create(['display_name' => 'Ana']);
    $luis = CommsPerson::factory()->for($user)->create(['display_name' => 'Luis']);
    $opened = CarbonImmutable::parse('2026-11-02T10:00:00Z');

    $earlier = assembledMessage($user, $ana, 'C1', 'Ayer el flujo', $opened->subHours(10));
    assembledMessage($user, $ana, 'C1', 'Demasiado viejo', $opened->subHours(49));
    assembledMessage($user, $ana, 'C1', 'Borrado', $opened->subHours(3), deleted: true);
    assembledMessage($user, $ana, 'C2', 'Otro canal', $opened->subHour());
    assembledMessage($user, $ana, 'C1', 'Otro servicio', $opened->subHour(), source: Source::Gmail);
    assembledMessage($user, $ana, 'C1', 'En el hilo', $opened->subHour(), thread: '111.222');
    assembledMessage($user, $ana, 'C1', 'En el mismo instante', $opened);
    $first = assembledMessage($user, $ana, 'C1', 'El flujo', $opened);
    $second = assembledMessage($user, $luis, 'C1', 'Lo miro', $opened->addMinute());

    $window = CommsWindow::query()->create([
        'user_id' => $user->id,
        'source' => 'slack',
        'group_ref' => 'C1',
        'kind' => 'channel',
        'message_ids' => [$first->id, $second->id],
        'opened_at' => $opened,
        'last_message_at' => $opened->addMinute(),
        'state' => 'open',
    ]);

    $assembled = app(WindowAssembler::class)->for($window);

    expect($assembled->messages)->toHaveCount(3)
        ->and(array_map(fn ($row): array => [$row->message->id, $row->new], $assembled->messages))->toEqual([
            [$earlier->id, false],
            [$first->id, true],
            [$second->id, true],
        ])
        ->and(array_map(fn ($person): string => $person->display_name, $assembled->people))->toEqual(['Ana', 'Luis']);
});

test('lookback keeps only the most recent messages inside the cap', function () {
    Config::set('comms.window.lookback_messages', 2);
    $user = User::factory()->create();
    $ana = CommsPerson::factory()->for($user)->create();
    $opened = CarbonImmutable::parse('2026-11-02T10:00:00Z');
    assembledMessage($user, $ana, 'C1', 'El más viejo', $opened->subHours(30));
    $middle = assembledMessage($user, $ana, 'C1', 'El del medio', $opened->subHours(10));
    $recent = assembledMessage($user, $ana, 'C1', 'El reciente', $opened->subHours(2));
    $current = assembledMessage($user, $ana, 'C1', 'Ahora', $opened);

    $window = CommsWindow::query()->create([
        'user_id' => $user->id,
        'source' => 'slack',
        'group_ref' => 'C1',
        'kind' => 'channel',
        'message_ids' => [$current->id],
        'opened_at' => $opened,
        'last_message_at' => $opened,
        'state' => 'open',
    ]);

    $ids = array_map(fn ($row): int => $row->message->id, app(WindowAssembler::class)->for($window)->messages);

    expect($ids)->toEqual([$middle->id, $recent->id, $current->id]);
});

test('a thread window looks back inside the thread', function () {
    $user = User::factory()->create();
    $ana = CommsPerson::factory()->for($user)->create();
    $opened = CarbonImmutable::parse('2026-11-02T10:00:00Z');
    $earlier = assembledMessage($user, $ana, 'C1', 'La primera del hilo', $opened->subHour(), thread: '111.222');
    assembledMessage($user, $ana, 'C1', 'En el canal', $opened->subMinutes(30));
    $current = assembledMessage($user, $ana, 'C1', 'La respuesta', $opened, thread: '111.222');

    $window = CommsWindow::query()->create([
        'user_id' => $user->id,
        'source' => 'slack',
        'group_ref' => '111.222',
        'kind' => 'thread',
        'message_ids' => [$current->id],
        'opened_at' => $opened,
        'last_message_at' => $opened,
        'state' => 'open',
    ]);

    $ids = array_map(fn ($row): int => $row->message->id, app(WindowAssembler::class)->for($window)->messages);

    expect($ids)->toEqual([$earlier->id, $current->id]);
});

test('messages that share a time stay in id order', function () {
    $user = User::factory()->create();
    $ana = CommsPerson::factory()->for($user)->create();
    $opened = CarbonImmutable::parse('2026-11-02T10:00:00Z');
    $at = $opened->subHour();
    $first = assembledMessage($user, $ana, 'C1', 'Uno', $at);
    $second = assembledMessage($user, $ana, 'C1', 'Dos', $at);
    $current = assembledMessage($user, $ana, 'C1', 'Ahora', $opened);

    $window = CommsWindow::query()->create([
        'user_id' => $user->id,
        'source' => 'slack',
        'group_ref' => 'C1',
        'kind' => 'channel',
        'message_ids' => [$current->id],
        'opened_at' => $opened,
        'last_message_at' => $opened,
        'state' => 'open',
    ]);

    $ids = array_map(fn ($row): int => $row->message->id, app(WindowAssembler::class)->for($window)->messages);

    expect($ids)->toEqual([$first->id, $second->id, $current->id]);
});

function assembledMessage(User $user, CommsPerson $author, string $channel, string $text, CarbonImmutable $at, bool $deleted = false, ?string $thread = null, Source $source = Source::Slack): CommsMessage
{
    return CommsMessage::factory()->create([
        'user_id' => $user->id,
        'author_person_id' => $author->id,
        'event_id' => CommsEvent::factory()->create([
            'user_id' => $user->id,
            'processed_at' => now(),
        ])->id,
        'source' => $source,
        'channel_ref' => $channel,
        'thread_ref' => $thread,
        'text' => $text,
        'direction' => 'in',
        'occurred_at' => $at,
        'deleted_at' => $deleted ? $at : null,
        'external_id' => 'T:'.$channel.':'.$at->getTimestamp().fake()->unique()->numerify('####'),
    ]);
}
