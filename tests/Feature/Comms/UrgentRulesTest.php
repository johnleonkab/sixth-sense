<?php

use App\Comms\Events\Projections;
use App\Comms\Events\Replay;
use App\Comms\Events\Source;
use App\Comms\Intents\Archive;
use App\Comms\Windows\UrgentRules;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIdentity;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsUrgentRule;
use App\Models\User;

test('each urgent rule kind matches its message and ignores the rest', function () {
    $user = User::factory()->create();
    $marta = CommsPerson::factory()->for($user)->create(['display_name' => 'Marta Ruiz', 'importance_weight' => 40]);
    $luis = CommsPerson::factory()->for($user)->create(['display_name' => 'Luis Gómez', 'importance_weight' => 10]);
    CommsIdentity::factory()->for($user)->for($marta, 'person')->create(['source' => Source::Slack, 'external_id' => 'U01']);

    $archive = app(Archive::class);
    $archive->record($user, urgentRule('set', 'person', 'slack:U01'));
    $archive->record($user, urgentRule('set', 'keyword', 'prepago', ['slack']));
    $archive->record($user, urgentRule('set', 'keyword', 'accion', ['slack']));
    $archive->record($user, urgentRule('set', 'channel', 'slack:C9'));
    $archive->record($user, urgentRule('set', 'keyword', 'reembolso', ['slack'], false));

    $rules = app(UrgentRules::class);

    expect($rules->matches($user, urgentMessage($user, $marta, 'Hola')))->toBeTrue()
        ->and($rules->matches($user, urgentMessage($user, $luis, 'Hola')))->toBeFalse()
        ->and($rules->matches($user, urgentMessage($user, $luis, 'El Prepago, antes del viernes.')))->toBeTrue()
        ->and($rules->matches($user, urgentMessage($user, $luis, 'prepagoextra')))->toBeFalse()
        ->and($rules->matches($user, urgentMessage($user, $luis, 'La acción ya está.')))->toBeTrue()
        ->and($rules->matches($user, urgentMessage($user, $luis, 'Mirad C9')))->toBeFalse()
        ->and($rules->matches($user, urgentMessage($user, $luis, 'Hola', 'C9')))->toBeTrue()
        ->and($rules->matches($user, urgentMessage($user, $luis, 'el reembolso')))->toBeFalse()
        ->and($rules->matches($user, urgentMessage($user, $luis, 'el prepago', 'C1', Source::Gmail)))->toBeFalse();
});

test('a direct message from someone at the weight is urgent, and a removed rule stops', function () {
    $user = User::factory()->create();
    $marta = CommsPerson::factory()->for($user)->create(['display_name' => 'Marta Ruiz', 'importance_weight' => 35]);
    $luis = CommsPerson::factory()->for($user)->create(['display_name' => 'Luis Gómez', 'importance_weight' => 34]);
    $rules = app(UrgentRules::class);

    expect(CommsUrgentRule::query()->count())->toBe(0)
        ->and($rules->matches($user, urgentMessage($user, $marta, '¿Lo ves?', 'D123')))->toBeTrue();

    $seeded = CommsUrgentRule::query()->whereBelongsTo($user)->sole();

    expect($seeded->kind)->toBe('person_weight')
        ->and($seeded->ref)->toBe('35')
        ->and($rules->matches($user, urgentMessage($user, $marta, '¿Lo ves?', 'C1')))->toBeFalse()
        ->and($rules->matches($user, urgentMessage($user, $marta, '¿Lo ves?', 'D123', Source::Slack, 'out')))->toBeFalse()
        ->and($rules->matches($user, urgentMessage($user, $luis, '¿Lo ves?', 'D123')))->toBeFalse();

    app(Archive::class)->record($user, urgentRule('remove', 'person_weight', '35'));

    expect(CommsUrgentRule::query()->whereBelongsTo($user)->count())->toBe(0)
        ->and($rules->matches($user, urgentMessage($user, $marta, '¿Lo ves?', 'D123')))->toBeFalse();
});

test('replay rebuilds urgent rules from the log', function () {
    $user = User::factory()->create();
    $archive = app(Archive::class);
    $archive->record($user, urgentRule('set', 'keyword', 'prepago'));
    $archive->record($user, urgentRule('set', 'channel', 'slack:C9', ['slack']));
    $archive->record($user, urgentRule('remove', 'keyword', 'prepago'));

    $before = app(Projections::class)->capture($user)['urgent_rules'];

    app(Replay::class)->run($user);

    expect(app(Projections::class)->capture($user)['urgent_rules'])->toEqual($before);
});

/**
 * @param  list<string>  $sources
 * @return array<string, mixed>
 */
function urgentRule(string $op, string $kind, string $ref, array $sources = [], bool $active = true): array
{
    return [
        'kind' => 'urgent_rule',
        'op' => $op,
        'rule' => ['kind' => $kind, 'ref' => $ref, 'sources' => $sources, 'active' => $active],
    ];
}

function urgentMessage(User $user, CommsPerson $author, string $text, string $channel = 'C1', Source $source = Source::Slack, string $direction = 'in'): CommsMessage
{
    return CommsMessage::factory()->create([
        'user_id' => $user->id,
        'author_person_id' => $author->id,
        'event_id' => CommsEvent::factory()->create(['user_id' => $user->id, 'processed_at' => now()])->id,
        'source' => $source,
        'channel_ref' => $channel,
        'text' => $text,
        'direction' => $direction,
    ]);
}
