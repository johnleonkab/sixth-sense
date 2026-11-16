<?php

use App\Comms\Backfill\Passes;
use App\Comms\Context\FactOrigin;
use App\Comms\Models\CompletesTasks;
use App\Comms\Models\FakeCompleter;
use App\Comms\Models\ModelCompletion;
use App\Models\Comms\CommsContextFact;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsGoldenLabel;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\User;

test('nineteen labelled messages do not ask the model or store a pattern', function () {
    $user = User::factory()->create();
    $dani = CommsPerson::factory()->for($user)->create(['display_name' => 'Dani']);
    askPatternLabels($user, $dani, 19);
    $fake = new FakeCompleter;
    app()->instance(CompletesTasks::class, $fake);

    $counts = app(Passes::class)->run($user, 'patterns');

    expect($counts)->toBe(['people' => 0, 'facts' => 0])
        ->and($fake->calls)->toBe([])
        ->and(CommsContextFact::query()->whereBelongsTo($user)->count())->toBe(0);
});

test('twenty labelled messages store at most five observed rules for that person', function () {
    $user = User::factory()->create();
    $dani = CommsPerson::factory()->for($user)->create(['display_name' => 'Dani']);
    $quiet = CommsPerson::factory()->for($user)->create(['display_name' => 'Luis']);
    askPatternLabels($user, $dani, 20);
    askPatternLabels($user, $quiet, 19);
    $fake = new FakeCompleter;
    $fake->script = [new ModelCompletion([
        'patterns' => array_map(
            fn (int $number): array => [
                'text' => "Dani pide así, patrón {$number}.",
                'example_message_ids' => ['m1'],
            ],
            range(1, 6),
        ),
    ], 'scripted')];
    app()->instance(CompletesTasks::class, $fake);

    $counts = app(Passes::class)->run($user, 'patterns');
    $facts = CommsContextFact::query()->whereBelongsTo($user)->where('kind', 'rule')->orderBy('id')->get();
    $input = $fake->calls[0]['input'] ?? '';

    expect($counts['facts'])->toBe(5)
        ->and($fake->calls)->toHaveCount(1)
        ->and(strpos($input, 'PERSON'))->toBeInt()
        ->and(strpos($input, 'ACTED'))->toBeGreaterThan(strpos($input, 'PERSON'))
        ->and(strpos($input, 'IGNORED'))->toBeGreaterThan(strpos($input, 'ACTED'))
        ->and($facts)->toHaveCount(5)
        ->and($facts->every(fn (CommsContextFact $fact): bool => $fact->subject === 'person:'.$dani->id && $fact->origin === FactOrigin::Observed))->toBeTrue()
        ->and(CommsContextFact::query()->where('subject', 'person:'.$quiet->id)->count())->toBe(0);
});

function askPatternLabels(User $user, CommsPerson $person, int $count): void
{
    foreach (range(1, $count) as $number) {
        $message = CommsMessage::factory()->create([
            'author_person_id' => $person->id,
            'direction' => 'in',
            'channel_ref' => 'C-producto',
            'text' => "Mensaje {$number} de {$person->display_name}",
            'event_id' => CommsEvent::factory()->create([
                'user_id' => $user->id,
                'processed_at' => now(),
            ])->id,
        ]);
        CommsGoldenLabel::query()->create([
            'user_id' => $user->id,
            'kind' => 'weak_label',
            'message_id' => $message->id,
            'label' => $number % 2 === 0 ? 'acted' : 'ignored',
            'evidence_event_ids' => [],
            'people' => ['person:'.$person->id],
            'topics' => [],
            'source_event_id' => $message->event_id,
        ]);
    }
}
