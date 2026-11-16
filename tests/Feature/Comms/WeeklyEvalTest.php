<?php

use App\Models\Comms\CommsEvalRun;
use App\Models\User;

test('a weekly run stores the golden-set metrics without changing a prompt', function () {
    $user = User::factory()->create();
    $link = config('comms.tasks.link.prompt');

    $this->artisan('comms:eval', ['user' => $user->id, '--weekly' => true])->assertSuccessful();

    $run = CommsEvalRun::query()->whereBelongsTo($user)->sole();

    expect($run->kind)->toBe('weekly')
        ->and($run->prompt_versions['link'])->toBe($link)
        ->and($run->metrics)->toHaveKeys([
            'retrieval_topic',
            'retrieval_intent',
            'attach_precision',
            'uncertain_share',
            'uncertain_moved_share',
            'needs_user_precision',
            'needs_user_recall',
            'critic_refutation_rate',
            'critic_agreement',
            'corrections_per_100',
        ])
        ->and(config('comms.tasks.link.prompt'))->toBe($link);
});

test('a fixture run with one worse metric fails the prompt change check', function () {
    $user = User::factory()->create();
    $link = config('comms.tasks.link.prompt');
    weeklyFixture($user, ['retrieval_topic' => 1.0]);
    weeklyFixture($user, ['retrieval_topic' => 0.5]);

    $this->artisan('comms:eval', ['user' => $user->id, '--prompt-change' => true])
        ->assertFailed()
        ->expectsOutputToContain('retrieval_topic');

    expect(CommsEvalRun::query()->whereBelongsTo($user)->count())->toBe(2)
        ->and(config('comms.tasks.link.prompt'))->toBe($link);
});

test('an equal fixture run passes the prompt change check', function () {
    $user = User::factory()->create();
    weeklyFixture($user, ['retrieval_topic' => 1.0, 'corrections_per_100' => 4.0]);
    weeklyFixture($user, ['retrieval_topic' => 1.0, 'corrections_per_100' => 4.0]);

    $this->artisan('comms:eval', ['user' => $user->id, '--prompt-change' => true])->assertSuccessful();

    expect(CommsEvalRun::query()->whereBelongsTo($user)->count())->toBe(2);
});

/**
 * @param  array<string, float>  $metrics
 */
function weeklyFixture(User $user, array $metrics): void
{
    CommsEvalRun::query()->create([
        'user_id' => $user->id,
        'kind' => 'weekly',
        'prompt_versions' => ['link' => 2],
        'metrics' => $metrics,
        'passed' => null,
    ]);
}
