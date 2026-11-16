<?php

use App\Models\Comms\CommsGoldenLabel;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsSegment;
use App\Models\Comms\CommsTopicAlias;
use App\Models\Comms\CommsTopicSearch;
use App\Models\Comms\CommsWindow;
use App\Models\User;
use Illuminate\Support\Str;

test('retrieval reports the share of expected topics and intents in the top ten', function () {
    $user = User::factory()->create();
    retrievalMetricTopic($user, 'workstream:prepago', 'Lanzamiento de primavera', ['SPR']);
    $intent = CommsIntent::factory()->create([
        'user_id' => $user->id,
        'what' => 'Nada que ver',
        'state' => 'open',
        'topic_subject' => 'workstream:prepago',
    ]);
    $miss = CommsIntent::factory()->create([
        'user_id' => $user->id,
        'what' => 'Otra frase sin parecido',
        'state' => 'open',
        'topic_subject' => 'workstream:otro',
    ]);
    retrievalMetricLabel($user, retrievalMetricSegment($user, 'cómo va el SPR'), [
        'topic' => 'workstream:prepago',
        'intent' => $intent->id,
    ], ['workstream:otro']);
    retrievalMetricLabel($user, retrievalMetricSegment($user, 'hola'), [
        'topic' => 'workstream:otro',
        'intent' => $miss->id,
    ]);
    retrievalMetricLabel($user, null, [], []);
    CommsGoldenLabel::query()->create([
        'user_id' => $user->id,
        'kind' => 'weak_label',
        'label' => 'acted',
        'evidence_event_ids' => [],
        'people' => [],
        'topics' => ['workstream:prepago'],
    ]);

    $this->artisan('comms:eval', ['user' => $user->id, '--task' => 'retrieve'])
        ->assertSuccessful()
        ->expectsOutputToContain('topic in top 10: 1 of 2')
        ->expectsOutputToContain('intent in top 10: 1 of 2');
});

test('an expected topic or intent past the tenth place counts as a miss', function () {
    $user = User::factory()->create();
    $words = [];

    for ($index = 1; $index <= 12; $index++) {
        $word = sprintf('alias%02d', $index);
        $words[] = $word;
        retrievalMetricTopic($user, sprintf('workstream:t%02d', $index), 'Otro asunto', [$word]);
    }

    $intents = [];

    for ($index = 1; $index <= 11; $index++) {
        $intents[] = CommsIntent::factory()->create([
            'user_id' => $user->id,
            'what' => 'Nada que ver '.$index,
            'state' => 'open',
            'topic_subject' => 'workstream:t01',
        ]);
    }

    $restated = implode(' ', $words);
    retrievalMetricLabel($user, retrievalMetricSegment($user, $restated), [
        'topic' => 'workstream:t01',
        'intent' => $intents[9]->id,
    ]);
    retrievalMetricLabel($user, retrievalMetricSegment($user, $restated), [
        'topic' => 'workstream:t11',
        'intent' => $intents[10]->id,
    ]);

    $this->artisan('comms:eval', ['user' => $user->id, '--task' => 'retrieve'])
        ->assertSuccessful()
        ->expectsOutputToContain('topic in top 10: 1 of 2')
        ->expectsOutputToContain('intent in top 10: 1 of 2');
});

test('a segment that names only a topic does not count toward intents', function () {
    $user = User::factory()->create();
    retrievalMetricTopic($user, 'workstream:prepago', 'Lanzamiento de primavera', ['SPR']);
    $intent = CommsIntent::factory()->create([
        'user_id' => $user->id,
        'what' => 'Otra frase sin parecido',
        'state' => 'open',
        'topic_subject' => 'workstream:otro',
    ]);
    retrievalMetricLabel($user, retrievalMetricSegment($user, 'cómo va el SPR'), [
        'topic' => 'workstream:prepago',
    ]);
    retrievalMetricLabel($user, retrievalMetricSegment($user, 'hola'), [
        'intent' => $intent->id,
    ]);

    $this->artisan('comms:eval', ['user' => $user->id, '--task' => 'retrieve'])
        ->assertSuccessful()
        ->expectsOutputToContain('topic in top 10: 1 of 1')
        ->expectsOutputToContain('intent in top 10: 0 of 1');
});

test('retrieval for one person leaves another person out', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    retrievalMetricTopic($user, 'workstream:prepago', 'Lanzamiento de primavera', ['SPR']);
    retrievalMetricTopic($other, 'workstream:prepago', 'Lanzamiento de primavera', ['SPR']);
    retrievalMetricLabel($user, retrievalMetricSegment($user, 'cómo va el SPR'), [
        'topic' => 'workstream:prepago',
    ]);
    retrievalMetricLabel($other, retrievalMetricSegment($other, 'hola'), [
        'topic' => 'workstream:prepago',
    ]);

    $this->artisan('comms:eval', ['user' => $user->id, '--task' => 'retrieve'])
        ->assertSuccessful()
        ->expectsOutputToContain('topic in top 10: 1 of 1')
        ->expectsOutputToContain('intent in top 10: 0 of 0');

    $this->artisan('comms:eval', ['--task' => 'retrieve'])
        ->assertSuccessful()
        ->expectsOutputToContain('topic in top 10: 1 of 2');
});

test('retrieval with no golden segments reports zero of zero', function () {
    $user = User::factory()->create();

    $this->artisan('comms:eval', ['user' => $user->id, '--task' => 'retrieve'])
        ->assertSuccessful()
        ->expectsOutputToContain('topic in top 10: 0 of 0')
        ->expectsOutputToContain('intent in top 10: 0 of 0');
});

test('an unknown eval task is rejected', function () {
    $user = User::factory()->create();

    $this->artisan('comms:eval', ['user' => $user->id])->assertFailed();
    $this->artisan('comms:eval', ['user' => $user->id, '--task' => 'weekly'])->assertFailed();
    $this->artisan('comms:eval', ['user' => 999999, '--task' => 'retrieve'])->assertFailed();
});

/**
 * @param  list<string>  $aliases
 */
function retrievalMetricTopic(User $user, string $subject, string $document, array $aliases): void
{
    CommsTopicSearch::query()->create([
        'user_id' => $user->id,
        'topic_subject' => $subject,
        'document' => $document,
    ]);

    foreach ($aliases as $alias) {
        CommsTopicAlias::query()->create([
            'user_id' => $user->id,
            'topic_subject' => $subject,
            'alias' => $alias,
            'alias_normalized' => trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($alias)))),
        ]);
    }
}

function retrievalMetricSegment(User $user, string $restated): CommsSegment
{
    $window = CommsWindow::query()->create([
        'user_id' => $user->id,
        'source' => 'slack',
        'group_ref' => 'C1',
        'kind' => 'channel',
        'message_ids' => [],
        'opened_at' => now(),
        'last_message_at' => now(),
        'state' => 'settled',
    ]);

    return CommsSegment::query()->create([
        'user_id' => $user->id,
        'window_id' => $window->id,
        'message_ids' => [],
        'new_message_ids' => [],
        'participants' => [],
        'restated' => $restated,
        'unresolved_refs' => [],
    ]);
}

/**
 * @param  array<string, mixed>  $expected
 * @param  list<string>  $topics
 */
function retrievalMetricLabel(User $user, ?CommsSegment $segment, array $expected, array $topics = []): CommsGoldenLabel
{
    return CommsGoldenLabel::query()->create([
        'user_id' => $user->id,
        'kind' => 'retrieval',
        'segment_id' => $segment?->id,
        'label' => 'expected',
        'expected' => $expected === [] ? null : $expected,
        'evidence_event_ids' => [],
        'people' => [],
        'topics' => $topics,
    ]);
}
