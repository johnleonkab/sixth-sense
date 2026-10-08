<?php

use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Connectors\Docs\Documents;
use App\Connectors\Docs\FakeDocuments;
use App\Connectors\Mail\FakeMailbox;
use App\Connectors\Mail\Mailbox;
use App\Connectors\Wiki\FakeWiki;
use App\Connectors\Wiki\Wiki;
use App\Jobs\BuildWorkContext;
use App\Models\User;
use App\Models\WorkContext;
use App\WorkContext\ContextSummarizer;
use App\WorkContext\Summarizers\FakeContextSummarizer;
use App\WorkContext\WorkActivity;
use App\WorkContext\WorkContextStatus;
use App\WorkContext\WorkDigest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->app->instance(Chats::class, new FakeChats);
    $this->app->instance(Mailbox::class, $this->mailbox = new FakeMailbox);
    $this->app->instance(Documents::class, new FakeDocuments);
    $this->app->instance(Wiki::class, new FakeWiki);
    $this->app->instance(ContextSummarizer::class, $this->summarizer = new FakeContextSummarizer);
    $this->user = User::factory()->create(['name' => 'John León']);
});

test('the nightly build reads every source and keeps what the person is working on', function () {
    $this->freezeSecond();
    WorkContext::factory()->for($this->user)->create();

    BuildWorkContext::dispatchSync($this->user);

    $context = $this->user->workContext()->first();
    $activity = $this->summarizer->summarized[0];

    expect($context->status)->toBe(WorkContextStatus::Ready)
        ->and($context->summary)->toStartWith('Estos días John León ha escrito o recibido')
        ->and($context->digest['workstreams'])->not->toBeEmpty()
        ->and($context->generated_at->equalTo(now()))->toBeTrue()
        ->and($context->sources)->toMatchArray([...$activity->counts(), 'failed' => []])
        ->and($context->usable())->toBeTrue()
        ->and($activity->messages)->not->toBeEmpty()
        ->and($activity->emails)->not->toBeEmpty()
        ->and($activity->documents)->toHaveCount(2)
        ->and($activity->documents[0]['excerpt'])->toContain('Flujo de prepago v4')
        ->and($activity->pages)->not->toBeEmpty();
});

test('email is read from the primary inbox and the sent folder of the last week', function () {
    $this->freezeSecond();
    WorkContext::factory()->for($this->user)->create();

    BuildWorkContext::dispatchSync($this->user);

    expect(array_map(fn ($search) => $search->folder, $this->mailbox->searches))->toBe(['primary', 'sent'])
        ->and($this->mailbox->searches[0]->after->equalTo(now()->subDays(7)))->toBeTrue();

    $refs = array_map(fn ($email) => $email->ref, $this->summarizer->summarized[0]->emails);
    expect($refs)->toBe(array_values(array_unique($refs)));
});

test('a source that fails is left out and the others are still read', function () {
    $this->app->instance(Wiki::class, Mockery::mock(FakeWiki::class, function ($wiki) {
        $wiki->shouldReceive('search')->andThrow(new RuntimeException('Notion is down'));
    }));
    WorkContext::factory()->for($this->user)->create();

    BuildWorkContext::dispatchSync($this->user);

    $context = $this->user->workContext()->first();

    expect($context->status)->toBe(WorkContextStatus::Ready)
        ->and($context->sources['notion'])->toBe(0)
        ->and($context->sources['failed'])->toBe(['notion']);
});

test('when the build fails the previous context is kept and the error shown', function () {
    $previous = WorkContext::factory()->for($this->user)->ready()->create();
    $this->app->instance(ContextSummarizer::class, new class implements ContextSummarizer
    {
        public function summarize(User $user, WorkActivity $activity): WorkDigest
        {
            throw new RuntimeException('Gemini work context failed with HTTP 429: quota');
        }
    });

    expect(fn () => BuildWorkContext::dispatchSync($this->user))->toThrow(RuntimeException::class);

    $context = $previous->refresh();

    expect($context->status)->toBe(WorkContextStatus::Failed)
        ->and($context->error)->toBe('Gemini work context failed with HTTP 429: quota')
        ->and($context->summary)->toBe($previous->summary)
        ->and($context->usable())->toBeTrue();
});

test('nothing is read once the person switched it off', function () {
    WorkContext::factory()->for($this->user)->create(['enabled' => false]);

    BuildWorkContext::dispatchSync($this->user);

    expect($this->summarizer->summarized)->toBeEmpty()
        ->and($this->user->workContext()->first()->status)->toBe(WorkContextStatus::Idle);
});

test('the nightly command only queues people who switched it on', function () {
    Queue::fake();
    $on = WorkContext::factory()->create()->user;
    WorkContext::factory()->create(['enabled' => false]);

    $this->artisan('context:build')->assertSuccessful();

    Queue::assertPushed(BuildWorkContext::class, 1);
    Queue::assertPushed(BuildWorkContext::class, fn (BuildWorkContext $job) => $job->user->is($on));
});

test('an old context is not given to the assistant', function () {
    $context = WorkContext::factory()->for($this->user)->ready()->create(['generated_at' => now()->subHours(WorkContext::FRESH_FOR_HOURS + 1)]);

    expect($context->usable())->toBeFalse();
});
