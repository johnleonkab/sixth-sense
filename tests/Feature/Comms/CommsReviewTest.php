<?php

use App\Comms\Cutover\CommsReview;
use App\Comms\People\Relation;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsIntentParticipant;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsNotification;
use App\Models\Comms\CommsPerson;
use App\Models\User;

test('an unspecified ask shows what was written, who is involved and every channel', function () {
    $user = User::factory()->create();
    $intent = CommsIntent::factory()->for($user)->create([
        'what' => 'Look at a Jira (unspecified)',
        'expects' => 'jira:PAY-1',
    ]);
    $clara = CommsPerson::factory()->for($user)->create([
        'display_name' => 'Clara Requena',
        'relation' => Relation::Collaborator,
    ]);
    $julian = CommsPerson::factory()->for($user)->create([
        'display_name' => 'Julián Enríquez Ruiz',
        'relation' => Relation::Collaborator,
    ]);
    CommsPerson::factory()->for($user)->create([
        'display_name' => 'John',
        'relation' => Relation::Self,
    ]);
    CommsIntentParticipant::factory()->for($user)->create([
        'intent_id' => $intent->id,
        'person_id' => $clara->id,
        'role' => 'requester',
    ]);
    CommsIntentParticipant::factory()->for($user)->create([
        'intent_id' => $intent->id,
        'person_id' => CommsPerson::query()->whereBelongsTo($user)->where('relation', Relation::Self)->value('id'),
        'role' => 'responder',
    ]);
    CommsIntentParticipant::factory()->for($user)->create([
        'intent_id' => $intent->id,
        'person_id' => $julian->id,
        'role' => 'informed',
    ]);
    $message = CommsMessage::factory()->create([
        'author_person_id' => $clara->id,
        'text' => 'vamos a darle prioridad a esto mañana porfa',
    ]);
    CommsConversationLink::factory()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'message_id' => $message->id,
    ]);
    $notification = CommsNotification::factory()->for($user)->create([
        'kind' => 'signal',
        'subject_type' => 'intent',
        'subject_id' => $intent->id,
        'source_event_ids' => [$message->event_id],
    ]);

    $props = app(CommsReview::class)->props($user, $notification, 'Europe/Madrid');

    expect($props['ask'])->toBe('vamos a darle prioridad a esto mañana porfa')
        ->and($props['ask_kind'])->toBe('words')
        ->and($props['people'])->toBe(['Clara Requena', 'Julián Enríquez Ruiz'])
        ->and($props['sources'])->toBe(['slack', 'jira']);
});
