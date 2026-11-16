<?php

use App\Comms\Backfill\IdentityPass;
use App\Comms\Backfill\Passes;
use App\Comms\Events\EventType;
use App\Comms\Events\Replay;
use App\Comms\Events\Source;
use App\Comms\Ingestion\GmailInbound;
use App\Comms\Ingestion\IdentityResolver;
use App\Comms\Ingestion\Ingestor;
use App\Comms\Messages\Party;
use App\Comms\Models\CompletesTasks;
use App\Comms\Models\FakeCompleter;
use App\Comms\Models\ModelCompletion;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIdentity;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\User;
use Carbon\CarbonImmutable;

test('the same email is already one person and the model is not asked', function () {
    $user = User::factory()->create();
    $resolver = app(IdentityResolver::class);
    $resolver->resolve($user, Source::Slack, new Party('U1', 'ana@acme.com', 'Ana Ruiz', handle: 'ana'), audit: true);
    $resolver->resolve($user, Source::Gmail, new Party('ana@acme.com', 'ana@acme.com', 'Ana'), audit: true);
    $fake = scriptedIdentity([]);

    $counts = app(IdentityPass::class)->run($user);

    expect(CommsPerson::query()->whereBelongsTo($user)->whereNull('merged_into_id')->count())->toBe(1)
        ->and($fake->calls)->toBeEmpty()
        ->and($counts)->toBe(['candidates' => 0, 'proposed' => 0]);
});

test('a shared display name never becomes a merge', function () {
    $user = User::factory()->create();
    $resolver = app(IdentityResolver::class);
    $resolver->resolve($user, Source::Slack, new Party('U1', 'ana@acme.com', 'Ana Ruiz', handle: 'ana'), audit: true);
    $resolver->resolve($user, Source::Gmail, new Party('g-ana', 'ana.ruiz@gmail.com', 'Ana Ruiz', handle: 'marta'), audit: true);
    $people = CommsPerson::query()->whereBelongsTo($user)->orderBy('id')->get();
    scriptedIdentity([[
        'a' => 'person:'.$people[0]->id,
        'b' => 'person:'.$people[1]->id,
        'verdict' => 'same',
        'evidence' => 'Se llaman igual.',
    ]]);

    $counts = app(IdentityPass::class)->run($user);

    expect($counts)->toBe(['candidates' => 1, 'proposed' => 0])
        ->and(app(IdentityPass::class)->proposals($user))->toBe([])
        ->and(CommsPerson::query()->whereBelongsTo($user)->whereNull('merged_into_id')->count())->toBe(2);
});

test('similar handles become a proposal the user confirms on Personas', function () {
    $user = User::factory()->create();
    $resolver = app(IdentityResolver::class);
    $slack = $resolver->resolve($user, Source::Slack, new Party('U1', 'ana@acme.com', 'Ana Ruiz', handle: 'anaruz'), audit: true);
    $gmail = $resolver->resolve($user, Source::Gmail, new Party('g-ana', 'ana.ruiz@gmail.com', 'Ana R.', handle: 'anaruzz'), audit: true);
    app(Ingestor::class)->ingest($user, new GmailInbound(
        'g-msg',
        'g-thread',
        new Party('g-ana', 'ana.ruiz@gmail.com', 'Ana R.', handle: 'anaruzz'),
        [],
        'Hola',
        'El identificador',
        CarbonImmutable::parse('2026-11-01T10:00:00Z'),
        ['INBOX'],
    ));
    CommsEvent::query()->whereBelongsTo($user)->whereNull('processed_at')->update(['processed_at' => now()]);
    $message = CommsMessage::query()->whereBelongsTo($user)->sole();
    $fake = scriptedIdentity([[
        'a' => 'person:'.$slack->id,
        'b' => 'person:'.$gmail->id,
        'verdict' => 'same',
        'evidence' => 'Los identificadores anaruz y anaruzz son el mismo.',
    ]]);

    $counts = app(Passes::class)->run($user, 'identity');

    expect($counts)->toBe(['candidates' => 1, 'proposed' => 1])
        ->and($fake->calls)->toHaveCount(1)
        ->and($fake->calls[0]['input'])->toContain('CANDIDATES')
        ->and($fake->calls[0]['input'])->toContain('person:'.$slack->id)
        ->and($fake->calls[0]['input'])->toContain('anaruzz');

    app(IdentityPass::class)->run($user);

    expect($fake->calls)->toHaveCount(1);

    $this->actingAs($user)->get(route('settings.context.index', ['tab' => 'people']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/context')
            ->where('merge_proposals.0.question', '¿Son la misma persona?')
            ->where('merge_proposals.0.evidence', 'Los identificadores anaruz y anaruzz son el mismo.')
            ->where('merge_proposals.0.names', ['Ana R.', 'Ana Ruiz']));

    $this->actingAs($user)->post(route('settings.context.people.merge'), [
        'from' => 'gmail:g-ana',
        'into' => 'slack:U1',
    ])->assertRedirect();

    $correction = CommsEvent::query()->where('type', EventType::UserCorrected)->sole();
    $survivor = CommsPerson::query()->whereBelongsTo($user)->whereNull('merged_into_id')->sole();

    expect($correction->normalized['action'])->toBe('merge')
        ->and($correction->normalized['kind'])->toBe('person')
        ->and($survivor->identities()->pluck('external_id')->sort()->values()->all())->toBe(['U1', 'g-ana'])
        ->and($message->refresh()->author_person_id)->toBe($survivor->id)
        ->and(app(IdentityPass::class)->proposals($user))->toBe([]);

    app(Replay::class)->run($user);

    $rebuilt = CommsPerson::query()->whereBelongsTo($user)->whereNull('merged_into_id')->sole();

    expect($rebuilt->identities()->pluck('external_id')->sort()->values()->all())->toBe(['U1', 'g-ana'])
        ->and(CommsMessage::query()->whereBelongsTo($user)->sole()->author_person_id)->toBe($rebuilt->id);
});

test('a different verdict stores no proposal', function () {
    $user = User::factory()->create();
    $resolver = app(IdentityResolver::class);
    $resolver->resolve($user, Source::Slack, new Party('U1', 'ana@acme.com', 'Ana Ruiz', handle: 'anaruz'), audit: true);
    $resolver->resolve($user, Source::Gmail, new Party('g-ana', 'ana.ruiz@gmail.com', 'Ana R.', handle: 'anaruzz'), audit: true);
    $people = CommsPerson::query()->whereBelongsTo($user)->orderBy('id')->get();
    scriptedIdentity([[
        'a' => 'person:'.$people[0]->id,
        'b' => 'person:'.$people[1]->id,
        'verdict' => 'different',
        'evidence' => 'El correo es de otra Ana.',
    ]]);

    expect(app(IdentityPass::class)->run($user))->toBe(['candidates' => 1, 'proposed' => 0])
        ->and(app(IdentityPass::class)->proposals($user))->toBe([]);
});

test('a person id the model invented is ignored', function () {
    $user = User::factory()->create();
    $resolver = app(IdentityResolver::class);
    $resolver->resolve($user, Source::Slack, new Party('U1', 'ana@acme.com', 'Ana Ruiz', handle: 'anaruz'), audit: true);
    $resolver->resolve($user, Source::Gmail, new Party('g-ana', 'ana.ruiz@gmail.com', 'Ana R.', handle: 'anaruzz'), audit: true);
    scriptedIdentity([[
        'a' => 'person:1',
        'b' => 'person:999999',
        'verdict' => 'same',
        'evidence' => 'Inventado.',
    ]]);

    expect(app(IdentityPass::class)->run($user)['proposed'])->toBe(0)
        ->and(CommsPerson::query()->whereBelongsTo($user)->whereNull('merged_into_id')->count())->toBe(2);
});

test('a failed identity model leaves the two people apart', function () {
    $user = User::factory()->create();
    $resolver = app(IdentityResolver::class);
    $resolver->resolve($user, Source::Slack, new Party('U1', 'ana@acme.com', 'Ana Ruiz', handle: 'anaruz'), audit: true);
    $resolver->resolve($user, Source::Gmail, new Party('g-ana', 'ana.ruiz@gmail.com', 'Ana R.', handle: 'anaruzz'), audit: true);
    scriptedIdentity([]);

    expect(app(IdentityPass::class)->run($user))->toBe(['candidates' => 1, 'proposed' => 0])
        ->and(CommsPerson::query()->whereBelongsTo($user)->whereNull('merged_into_id')->count())->toBe(2);
});

test('confirming a merge requires the signed-in owner', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $this->post(route('settings.context.people.merge'), ['from' => 'slack:U1', 'into' => 'gmail:g-ana'])
        ->assertRedirect(route('login'));

    $this->actingAs($other)->post(route('settings.context.people.merge'), [
        'from' => 'gmail:g-ana',
        'into' => 'slack:U1',
    ])->assertNotFound();

    $this->actingAs($owner)->post(route('settings.context.people.merge'), [])
        ->assertSessionHasErrors(['from', 'into']);

    expect(CommsIdentity::query()->count())->toBe(0);
});

/**
 * @param  list<array<string, mixed>>  $pairs
 */
function scriptedIdentity(array $pairs): FakeCompleter
{
    $fake = new FakeCompleter;
    $fake->script = $pairs === []
        ? []
        : [new ModelCompletion(['pairs' => $pairs], 'scripted')];
    app()->instance(CompletesTasks::class, $fake);

    return $fake;
}
