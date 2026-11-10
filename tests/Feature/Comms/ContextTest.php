<?php

use App\Comms\Context\Audience;
use App\Comms\Context\ContextEngine;
use App\Comms\Context\ContextQuestions;
use App\Comms\Context\ContextRenderer;
use App\Comms\Context\ContextRequest;
use App\Comms\Context\ContextWriter;
use App\Comms\Context\FactOrigin;
use App\Comms\Events\Replay;
use App\Knowledge\Gaps\GapKind;
use App\Knowledge\Gaps\GapQuestions;
use App\Models\Comms\CommsContextFact;
use App\Models\KnowledgeDocument;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

test('a fact the person stated wins over one the model guessed', function () {
    $user = User::factory()->create();
    $writer = app(ContextWriter::class);
    $operation = fn (string $relation): array => [[
        'op' => 'set',
        'kind' => 'person',
        'subject' => 'person:marta',
        'attribute' => 'relation',
        'value' => ['value' => $relation],
        'audiences' => [],
    ]];

    $writer->record($user, FactOrigin::User, $operation('client'));
    $writer->record($user, FactOrigin::Inferred, $operation('boss'));

    expect(CommsContextFact::query()->where('status', 'active')->sole()->text())->toBe('client')
        ->and(CommsContextFact::query()->where('status', 'proposed')->sole()->text())->toBe('boss');
});

test('a full name brings that person first, not everyone with the same first name', function () {
    $user = User::factory()->create();
    $writer = app(ContextWriter::class);
    $note = fn (string $subject, string $text): array => [[
        'op' => 'set',
        'kind' => 'person',
        'subject' => $subject,
        'attribute' => 'note',
        'value' => ['text' => $text, 'name' => Str::headline(Str::after($subject, 'person:'))],
        'audiences' => [],
    ]];

    $writer->record($user, FactOrigin::Inferred, $note('person:marta-prueba', 'Prueba de sixth-sense.'));
    $this->travel(1)->minute();
    $writer->record($user, FactOrigin::Inferred, $note('person:marta-bellido', 'Colega del equipo.'));

    $engine = app(ContextEngine::class);

    expect($engine->pack($user, new ContextRequest(Audience::Match, about: 'Marta Prueba'))->facts[0]['subject'])->toBe('person:marta-prueba')
        ->and($engine->pack($user, new ContextRequest(Audience::Match, about: 'Marta Prueba <marta@example.com>'))->facts[0]['subject'])->toBe('person:marta-prueba')
        ->and($engine->pack($user, new ContextRequest(Audience::Match, about: 'Marta'))->facts[0]['subject'])->toStartWith('person:marta-');
});

test('a rejected fact is not brought back by a later guess', function () {
    $user = User::factory()->create();
    $writer = app(ContextWriter::class);
    $operation = [[
        'op' => 'set',
        'kind' => 'rule',
        'subject' => 'user',
        'attribute' => 'rule',
        'value' => ['text' => 'Los avisos de facturación no importan.'],
        'audiences' => [],
    ]];

    $writer->record($user, FactOrigin::Inferred, $operation);
    $writer->record($user, FactOrigin::User, [[
        'op' => 'reject',
        'kind' => 'rule',
        'subject' => 'user',
        'attribute' => 'rule',
        'value' => ['text' => 'Los avisos de facturación no importan.'],
    ]]);
    $writer->record($user, FactOrigin::Inferred, $operation);

    expect(CommsContextFact::query()->where('status', 'active')->count())->toBe(0);
});

test('replay rebuilds the facts from the log', function () {
    $user = User::factory()->create();

    app(ContextWriter::class)->record($user, FactOrigin::User, [[
        'op' => 'set',
        'kind' => 'preference',
        'subject' => 'user',
        'attribute' => 'locale',
        'value' => ['value' => 'es'],
        'audiences' => [],
    ]]);

    app(Replay::class)->run($user);

    expect(CommsContextFact::query()->whereBelongsTo($user)->where('status', 'active')->sole()->text())->toBe('es');
});

test('search reads the knowledge corpus', function () {
    $user = User::factory()->create();
    KnowledgeDocument::factory()->for($user)->create([
        'title' => 'Flujo de prepago',
        'summary' => 'El flujo de prepago cobra antes de la reserva.',
    ]);

    $hits = app(ContextEngine::class)->search($user, 'cómo va el prepago');

    expect($hits)->not->toBeEmpty()
        ->and($hits[0]['document']->title)->toBe('Flujo de prepago');
});

test('outside the context classes nothing reads the fact table or the old stores', function () {
    $forbidden = ['CommsContextFact', 'IntentLesson', 'SlackAttentionProfile', 'CommsDocument', 'comms_documents', 'intent_lessons', 'slack_attention_profiles', 'forAssistant(CarbonImmutable', "where('status', GapStatus::Answered)", 'workContext->digest', 'workContext?->digest', 'workContext->summary'];
    $allowed = ['app/Comms/Context/', 'app/Models/Comms/CommsContextFact.php', 'app/Models/Comms/CommsContextUse.php', 'app/Comms/Events/Projections.php'];
    $offenders = [];

    foreach (File::allFiles(app_path()) as $file) {
        $path = Str::after($file->getPathname(), base_path().'/');

        if (Str::startsWith($path, $allowed)) {
            continue;
        }

        foreach ($forbidden as $needle) {
            if (str_contains($file->getContents(), $needle)) {
                $offenders[] = "{$path}: {$needle}";
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('a rule reaches only the audience it was learned for, with its ref', function () {
    $user = User::factory()->create();
    app(ContextWriter::class)->rule($user, 'Con Marta, tono informal.', 'reply', 'Marta');

    $reply = app(ContextRenderer::class)->block($user, new ContextRequest(Audience::Reply, about: 'marta'));
    $triage = app(ContextRenderer::class)->block($user, new ContextRequest(Audience::Triage, about: 'marta'));

    expect($reply)->toContain('Con Marta, tono informal.')->toMatch('/\\[fact:\\d+\\]/')
        ->and($triage)->not->toContain('Con Marta');
});

test('a rule on the same topic replaces the older one', function () {
    $user = User::factory()->create();
    $writer = app(ContextWriter::class);
    $writer->rule($user, 'Las reuniones con Marta duran 30 minutos.', 'scheduler', 'Marta', 'duration');
    $writer->rule($user, 'Las reuniones con Marta duran 45 minutos.', 'scheduler', 'Marta', 'duration');

    expect(CommsContextFact::query()->whereBelongsTo($user)->where('status', 'active')->sole()->text())->toBe('Las reuniones con Marta duran 45 minutos.');
});

test('the pack records which facts were used', function () {
    $user = User::factory()->create();
    app(ContextWriter::class)->rule($user, 'Nada de emojis.', 'reply');

    app(ContextEngine::class)->pack($user, new ContextRequest(Audience::Reply, callRef: 'test'));

    expect(CommsContextFact::query()->whereBelongsTo($user)->sole()->uses)->toBe(1);
});

test('a guess that keeps contradicting the person becomes one question, and yes accepts it', function () {
    $user = User::factory()->create();
    $writer = app(ContextWriter::class);
    $operation = fn (string $relation): array => [[
        'op' => 'set',
        'kind' => 'person',
        'subject' => 'person:marta',
        'attribute' => 'relation',
        'value' => ['value' => $relation, 'name' => 'Marta'],
        'audiences' => [],
    ]];

    $writer->record($user, FactOrigin::User, $operation('client'));

    foreach ([9, 5, 1] as $daysAgo) {
        $this->travelTo(now()->subDays($daysAgo));
        $writer->record($user, FactOrigin::Inferred, $operation('boss'));
        $this->travelBack();
    }

    expect(app(ContextQuestions::class)->find($user))->toBe(1)
        ->and(app(ContextQuestions::class)->find($user))->toBe(0);

    $gap = $user->knowledgeGaps()->where('kind', GapKind::Fact)->sole();
    app(GapQuestions::class)->answer($gap, 'Sí, ahora es mi jefa');

    expect(CommsContextFact::query()->whereBelongsTo($user)->where('status', 'active')->sole()->text())->toBe('boss');
});

test('an answered question is a fact the voice and the corpus read', function () {
    $user = User::factory()->create();
    $gap = $user->knowledgeGaps()->create([
        'kind' => GapKind::Term,
        'subject_key' => 'cm',
        'subject' => 'CM',
        'question' => '¿Qué es CM?',
    ]);

    app(GapQuestions::class)->answer($gap, 'CoverManager, la plataforma de reservas.');

    expect(app(ContextEngine::class)->answers($user))->toHaveCount(1)
        ->and(app(ContextRenderer::class)->voice($user))->toContain('CoverManager');
});
