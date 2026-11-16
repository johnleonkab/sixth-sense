<?php

use App\Comms\Linking\TopicSearch;
use App\Models\Comms\CommsTopicAlias;
use App\Models\Comms\CommsTopicSearch;
use App\Models\User;
use Illuminate\Support\Str;

test('an exact nickname finds that person\'s topic', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    topicRank($user, 'workstream:prepago', 'Lanzamiento de primavera', ['SPR']);
    topicRank($other, 'workstream:prepago', 'Lanzamiento de primavera', ['SPR']);

    expect(app(TopicSearch::class)->for($user, 'cómo va el SPR', []))->toBe(['workstream:prepago']);
});

test('a prefix and a close spelling still find the nickname', function () {
    $user = User::factory()->create();
    topicRank($user, 'workstream:prepago', 'Lanzamiento de primavera', ['prepago']);
    topicRank($user, 'workstream:spring', 'El calendario', ['spring']);
    topicRank($user, 'workstream:lejos', 'Otra cosa', ['calendario']);

    expect(app(TopicSearch::class)->for($user, 'prepa', []))->toBe(['workstream:prepago'])
        ->and(app(TopicSearch::class)->for($user, 'sprng', []))->toBe(['workstream:spring']);
});

test('an exact nickname ranks ahead of one that only starts the same', function () {
    $user = User::factory()->create();
    topicRank($user, 'workstream:largo', 'Otro asunto', ['prepagoplus']);
    topicRank($user, 'workstream:prepago', 'Otro asunto', ['prepago']);

    expect(app(TopicSearch::class)->for($user, 'prepago', []))->toBe([
        'workstream:prepago',
        'workstream:largo',
    ]);
});

test('a topic the words describe outranks a nickname mentioned once', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    topicRank($user, 'workstream:lanzamiento', 'Lanzamiento de primavera', ['spr']);
    topicRank($user, 'workstream:reembolso', 'Reembolso del cliente antes del viernes');
    topicRank($other, 'workstream:reembolso', 'Reembolso del cliente antes del viernes');

    expect(app(TopicSearch::class)->for($user, 'spr reembolso del cliente antes del viernes', []))->toBe([
        'workstream:reembolso',
        'workstream:lanzamiento',
    ]);
});

test('a term finds the topic when the sentence does not say it', function () {
    $user = User::factory()->create();
    topicRank($user, 'workstream:prepago', 'Lanzamiento de primavera', ['SPR']);

    expect(app(TopicSearch::class)->for($user, 'hola', ['SPR']))->toBe(['workstream:prepago']);
});

test('search returns at most ten topics', function () {
    $user = User::factory()->create();
    $words = [];

    for ($index = 1; $index <= 12; $index++) {
        $word = sprintf('alias%02d', $index);
        $words[] = $word;
        topicRank($user, sprintf('workstream:t%02d', $index), 'Otro asunto', [$word]);
    }

    expect(app(TopicSearch::class)->for($user, implode(' ', $words), []))->toBe(array_map(
        fn (int $index): string => sprintf('workstream:t%02d', $index),
        range(1, 10),
    ));
});

test('an empty question finds nothing', function () {
    $user = User::factory()->create();
    topicRank($user, 'workstream:prepago', 'Lanzamiento de primavera', ['SPR']);

    expect(app(TopicSearch::class)->for($user, '   ', []))->toBe([]);
});

/**
 * @param  list<string>  $aliases
 */
function topicRank(User $user, string $subject, string $document, array $aliases = []): void
{
    CommsTopicSearch::query()->create([
        'user_id' => $user->id,
        'topic_subject' => $subject,
        'document' => $document,
    ]);

    foreach ($aliases as $alias) {
        $normalized = trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($alias))));
        CommsTopicAlias::query()->create([
            'user_id' => $user->id,
            'topic_subject' => $subject,
            'alias' => $alias,
            'alias_normalized' => $normalized,
        ]);
    }
}
