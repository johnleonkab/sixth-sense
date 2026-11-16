<?php

use App\Comms\Context\CorrectionRules;
use App\Comms\Events\EventType;
use App\Comms\Linking\AgreedOperation;
use App\Comms\Linking\AgreementStatus;
use App\Comms\Linking\CriticStage;
use App\Comms\Linking\ExtractStage;
use App\Comms\Linking\LinkCertainty;
use App\Comms\Linking\LinkOutcome;
use App\Comms\Linking\LinkStage;
use App\Comms\Models\CompletesTasks;
use App\Comms\Models\FakeCompleter;
use App\Comms\Models\ModelCompletion;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsIntentParticipant;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsSegment;
use App\Models\Comms\CommsWindow;
use App\Models\User;

test('a correction about Marta appears in her next segment and not in someone else\'s', function () {
    $user = User::factory()->create();
    $marta = CommsPerson::factory()->for($user)->create(['display_name' => 'Marta']);
    $luis = CommsPerson::factory()->for($user)->create(['display_name' => 'Luis']);
    $intent = CommsIntent::factory()->create([
        'user_id' => $user->id,
        'what' => 'Revisar el contrato',
        'topic_subject' => 'workstream:contrato',
    ]);
    CommsIntentParticipant::query()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'person_id' => $marta->id,
        'role' => 'requester',
    ]);
    app(CorrectionRules::class)->learn(CommsEvent::factory()->create([
        'user_id' => $user->id,
        'type' => EventType::UserCorrected,
        'normalized' => ['action' => 'not_for_me', 'intent_key' => $intent->key],
    ]));
    $sentence = 'Con Marta, «Revisar el contrato» no era para ella.';
    $martaSegment = exampleSegment($user, $marta, '¿Me revisas el contrato?');
    $luisSegment = exampleSegment($user, $luis, '¿Me pasas el informe?');

    $martaLink = exampleRun(fn () => app(LinkStage::class)->run($martaSegment), exampleNone('¿Me revisas el contrato?'));
    $luisLink = exampleRun(fn () => app(LinkStage::class)->run($luisSegment), exampleNone('¿Me pasas el informe?'));
    $martaExtract = exampleRun(
        fn () => app(ExtractStage::class)->run($martaSegment, exampleOutcome('workstream:contrato')),
        ['op' => 'noise', 'quote' => '¿Me revisas el contrato?'],
    );
    $luisExtract = exampleRun(
        fn () => app(ExtractStage::class)->run($luisSegment, exampleOutcome('workstream:informe')),
        ['op' => 'noise', 'quote' => '¿Me pasas el informe?'],
    );
    $critic = new AgreedOperation(['op' => 'open', 'quote' => '¿Me revisas el contrato?', 'what' => 'Revisar el contrato'], AgreementStatus::Agreed, [], 3);
    $martaCritic = exampleRun(fn () => app(CriticStage::class)->run($martaSegment, [$critic], 'workstream:contrato'), exampleCritic());
    $luisCritic = exampleRun(fn () => app(CriticStage::class)->run($luisSegment, [$critic], 'workstream:informe'), exampleCritic());

    expect($martaLink)->toContain($sentence)
        ->and($martaLink)->toContain('PAST CORRECTIONS')
        ->and($luisLink)->not->toContain($sentence)
        ->and($martaExtract)->toContain($sentence)
        ->and($luisExtract)->not->toContain($sentence)
        ->and($martaCritic)->toContain($sentence)
        ->and($luisCritic)->not->toContain($sentence)
        ->and($martaLink)->toContain('not for the user');
});

function exampleSegment(User $user, CommsPerson $author, string $text): CommsSegment
{
    $message = CommsMessage::factory()->create([
        'author_person_id' => $author->id,
        'text' => $text,
        'direction' => 'in',
        'event_id' => CommsEvent::factory()->create([
            'user_id' => $user->id,
            'processed_at' => now(),
        ])->id,
    ]);
    $window = CommsWindow::query()->create([
        'user_id' => $user->id,
        'source' => 'slack',
        'group_ref' => 'C'.$author->id,
        'kind' => 'channel',
        'message_ids' => [$message->id],
        'opened_at' => $message->occurred_at,
        'last_message_at' => $message->occurred_at,
        'state' => 'settled',
    ]);

    return CommsSegment::query()->create([
        'user_id' => $user->id,
        'window_id' => $window->id,
        'message_ids' => [$message->id],
        'new_message_ids' => [$message->id],
        'participants' => ['person:'.$author->id],
        'restated' => $text,
        'unresolved_refs' => [],
    ]);
}

/**
 * @param  Closure(): mixed  $run
 * @param  array<string, mixed>  $body
 */
function exampleRun(Closure $run, array $body): string
{
    $fake = new FakeCompleter;
    $fake->script = array_map(
        fn (): ModelCompletion => new ModelCompletion($body, 'scripted'),
        range(1, 6),
    );
    app()->instance(CompletesTasks::class, $fake);
    $run();

    return (string) ($fake->calls[0]['system'] ?? '')."\n".(string) ($fake->calls[0]['input'] ?? '');
}

/**
 * @return array<string, mixed>
 */
function exampleNone(string $quote): array
{
    return ['decision' => 'none', 'quote' => $quote, 'why' => 'Chatter.'];
}

/**
 * @return array<string, mixed>
 */
function exampleCritic(): array
{
    $answer = ['verdict' => 'holds', 'quote' => null];

    return [
        'addressed_to_user' => $answer,
        'already_done' => $answer,
        'real_ask' => $answer,
    ];
}

function exampleOutcome(string $topic): LinkOutcome
{
    return new LinkOutcome('topic:'.$topic, $topic, LinkCertainty::Certain, '3of3', null, 'cita', null, []);
}
