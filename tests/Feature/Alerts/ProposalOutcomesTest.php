<?php

use App\Actions\Actions;
use App\Alerts\AlertFollowUp;
use App\Alerts\AlertSource;
use App\Alerts\Outcomes\ProposalOutcomes;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Connectors\Mail\FakeMailbox;
use App\Connectors\Mail\Mailbox;
use App\Models\InboxItem;
use App\Models\PendingAction;
use App\Models\ProposalOutcome;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->app->instance(Chats::class, $this->chats = new FakeChats);
    $this->app->instance(Mailbox::class, new FakeMailbox);
    $this->user = User::factory()->create();
    $this->alert = InboxItem::factory()->for($this->user)->create([
        'source' => AlertSource::Slack,
        'external_id' => 'demo:C01:1001.000400',
        'received_at' => now()->subMinutes(10),
        'proposal' => ['summary' => 'Confirmarle que lo revisas.', 'reply' => ['to' => 'thread', 'text' => 'Lo reviso y te confirmo.']],
    ]);
});

test('sending the proposal as it is records it as sent as is, with how long it took', function () {
    $this->actingAs($this->user)->postJson(route('alerts.reply', $this->alert))->assertOk();

    expect($this->alert->outcomes()->sole())
        ->outcome->toBe(ProposalOutcome::SENT)
        ->class->toBe('reply')
        ->action_type->toBe('chat.send')
        ->edit_size->toBe(0)
        ->seconds_to_outcome->toBeGreaterThanOrEqual(600);
});

test('editing the reply before sending sends the edit and records how much changed', function () {
    $this->actingAs($this->user)->postJson(route('alerts.reply', $this->alert), ['text' => 'Lo reviso esta tarde y te confirmo.'])->assertOk();

    $outcome = $this->alert->outcomes()->sole();

    expect($this->chats->sent[0]->text)->toBe('Lo reviso esta tarde y te confirmo.')
        ->and($outcome->outcome)->toBe(ProposalOutcome::EDITED)
        ->and($outcome->edit_size)->toBe(11)
        ->and($outcome->detail)->toBe(['proposed' => 'Lo reviso y te confirmo.', 'sent' => 'Lo reviso esta tarde y te confirmo.']);
});

test('undoing within the window records that it never went', function () {
    Queue::fake();
    $this->actingAs($this->user)->postJson(route('alerts.reply', $this->alert))->assertOk();

    $this->actingAs($this->user)->postJson(route('actions.undo', PendingAction::sole()))->assertOk();

    expect($this->alert->outcomes()->sole()->outcome)->toBe(ProposalOutcome::UNDONE);
});

test('discarding records the reason chosen, resolving records it, and reopening forgets both', function () {
    $resolve = fn (array $arguments) => $this->actingAs($this->user)->postJson(route('voice.tools.run', 'resolve_alert'), ['arguments' => ['alert_id' => $this->alert->id, ...$arguments]])->assertOk();

    $resolve(['outcome' => 'dismissed', 'reason' => 'not_for_me']);
    expect($this->alert->outcomes()->sole()->only(['outcome', 'reason']))->toBe(['outcome' => ProposalOutcome::DISCARDED, 'reason' => 'not_for_me']);

    $resolve(['outcome' => 'reopen']);
    expect($this->alert->outcomes()->count())->toBe(0);

    $resolve(['outcome' => 'done']);
    expect($this->alert->outcomes()->sole()->only(['outcome', 'reason']))->toBe(['outcome' => ProposalOutcome::RESOLVED, 'reason' => 'marked_done']);
});

test('discarding from the alert list takes a reason too, or none', function () {
    $this->actingAs($this->user)->postJson(route('alerts.dismiss', $this->alert), ['reason' => 'not_important'])->assertOk();
    $other = InboxItem::factory()->for($this->user)->create();
    $this->actingAs($this->user)->postJson(route('alerts.dismiss', $other))->assertOk();

    expect($this->alert->outcomes()->sole()->reason)->toBe('not_important')
        ->and($other->outcomes()->sole()->reason)->toBeNull();

    $this->actingAs($this->user)->postJson(route('alerts.dismiss', InboxItem::factory()->for($this->user)->create()), ['reason' => 'otra'])->assertUnprocessable();
});

test('an alert closed because the person answered elsewhere is resolved outside, unless it was sent from here', function () {
    $this->chats->answered = ['demo:C01:1001.000400'];

    app(AlertFollowUp::class)->checkReplies($this->user);

    expect($this->alert->outcomes()->sole()->only(['outcome', 'reason']))->toBe(['outcome' => ProposalOutcome::RESOLVED, 'reason' => 'replied_outside']);

    $sentHere = InboxItem::factory()->for($this->user)->create(['source' => AlertSource::Slack, 'external_id' => 'demo:C01:1001.000500', 'proposal' => ['reply' => ['to' => 'thread', 'text' => 'Ok']]]);
    $sentHere->outcomes()->create(['user_id' => $this->user->id, 'outcome' => ProposalOutcome::SENT]);

    expect(app(ProposalOutcomes::class)->resolved($sentHere, 'replied_outside'))->toBeNull()
        ->and(app(ProposalOutcomes::class)->expired($sentHere, 'superseded'))->toBeNull()
        ->and(app(ProposalOutcomes::class)->expired($this->alert->refresh(), 'superseded')->outcome)->toBe(ProposalOutcome::EXPIRED);
});

test('a reply proposed by voice or by an agent to an alert is recorded against it when confirmed', function () {
    $proposal = $this->actingAs($this->user)->postJson(route('voice.tools.run', 'propose_slack_message'), ['arguments' => ['reply_to' => 'demo:C01:1001.000400', 'text' => 'Mañana lo miro.']]);

    app(Actions::class)->confirm(PendingAction::query()->findOrFail($proposal->json('output.pending_action_id')));

    expect($this->alert->outcomes()->sole())
        ->outcome->toBe(ProposalOutcome::EDITED)
        ->detail->toBe(['proposed' => 'Lo reviso y te confirmo.', 'sent' => 'Mañana lo miro.']);
});

test('the size of an edit is counted in characters, also for long texts', function () {
    expect(ProposalOutcomes::editSize('Hola', 'Hola'))->toBe(0)
        ->and(ProposalOutcomes::editSize('Hola Marta', 'Hola Marta, gracias'))->toBe(9)
        ->and(ProposalOutcomes::editSize(str_repeat('a', 1200), str_repeat('a', 1100).str_repeat('b', 100)))->toBeGreaterThan(50);
});
