<?php

use App\Actions\Actions;
use App\Actions\ActionStatus;
use App\Connectors\Docs\Documents;
use App\Connectors\Docs\FakeDocuments;
use App\Jobs\ExecutePendingAction;
use App\Models\PendingAction;
use App\Models\User;
use App\Voice\Tools\Toolbox;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->app->instance(Documents::class, $this->documents = new FakeDocuments);
    $this->user = User::factory()->create();
});

function commentAction(User $user): PendingAction
{
    return app(Actions::class)->propose($user, 'docs.comment', 'Comentar en «PRD»', [
        'ref' => 'demo.'.FakeDocuments::PREPAGO_ID, 'content' => 'Revisado, ok.', 'quote' => null, 'url' => null,
    ]);
}

test('confirming runs the action once the undo window closes', function () {
    Queue::fake();
    $action = commentAction($this->user);

    $this->actingAs($this->user)
        ->postJson(route('actions.confirm', $action))
        ->assertOk()
        ->assertJsonPath('status', 'confirmed');

    Queue::assertPushed(ExecutePendingAction::class, fn (ExecutePendingAction $job) => $job->action->is($action)
        && now()->diffInSeconds($job->delay) >= Actions::UNDO_WINDOW - 1);
    expect($this->documents->comments)->toBe([]);
});

test('the job applies the confirmed action and records the result', function () {
    $action = commentAction($this->user);
    $this->actingAs($this->user)->postJson(route('actions.confirm', $action));

    expect($action->refresh()->status)->toBe(ActionStatus::Executed)
        ->and($action->result['comment_id'])->toBe('comment-1')
        ->and($this->documents->comments[0]['content'])->toBe('Revisado, ok.');
});

test('an undone action never runs', function () {
    Queue::fake();
    $action = commentAction($this->user);
    $this->actingAs($this->user)->postJson(route('actions.confirm', $action));

    $this->actingAs($this->user)->postJson(route('actions.undo', $action))->assertJsonPath('status', 'undone');
    (new ExecutePendingAction($action))->handle(app(Actions::class));

    expect($action->refresh()->status)->toBe(ActionStatus::Undone)
        ->and($this->documents->comments)->toBe([]);
});

test('a cancelled proposal can not be confirmed afterwards', function () {
    $action = commentAction($this->user);

    $this->actingAs($this->user)->postJson(route('actions.cancel', $action))->assertJsonPath('status', 'cancelled');
    $this->actingAs($this->user)->postJson(route('actions.confirm', $action))->assertJsonPath('status', 'cancelled');

    expect($this->documents->comments)->toBe([]);
});

test('an old proposal expires instead of being confirmed', function () {
    $action = commentAction($this->user);
    $this->travel(Actions::PROPOSAL_LIFETIME + 1)->seconds();

    $this->actingAs($this->user)->postJson(route('actions.confirm', $action))->assertJsonPath('status', 'expired');
});

test('failures are recorded with their reason', function () {
    $action = app(Actions::class)->propose($this->user, 'docs.edit', 'Cambiar', ['plan' => [
        'ref' => 'demo.'.FakeDocuments::PREPAGO_ID, 'title' => 'PRD', 'revision_id' => 'stale', 'changes' => [], 'operations' => [], 'url' => null,
    ]]);

    $this->actingAs($this->user)->postJson(route('actions.confirm', $action));

    expect($action->refresh()->status)->toBe(ActionStatus::Failed)
        ->and($action->error)->toContain('ha cambiado');
});

test('users can only answer their own proposals', function () {
    $action = commentAction(User::factory()->create());

    $this->actingAs($this->user)->postJson(route('actions.confirm', $action))->assertNotFound();
    $this->actingAs($this->user)->getJson(route('actions.show', $action))->assertNotFound();
});

test('the assistant has no tool to confirm anything', function () {
    $names = array_column(app(Toolbox::class)->declarations(), 'name');

    expect(array_filter($names, fn (string $name) => preg_match('/confirm|approve|execute|apply/', $name)))->toBe([]);
});
