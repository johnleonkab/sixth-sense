<?php

use App\Actions\Actions;
use App\Alerts\AlertFollowUp;
use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Alerts\Replies\FakeReplyPlanner;
use App\Alerts\Replies\ReplyPlanner;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Connectors\Mail\FakeMailbox;
use App\Connectors\Mail\Mailbox;
use App\Events\InboxItemUpdated;
use App\Jobs\CheckAlertReplies;
use App\Jobs\ExecutePendingAction;
use App\Models\InboxItem;
use App\Models\PendingAction;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->app->instance(Mailbox::class, $this->mailbox = new FakeMailbox);
    $this->app->instance(Chats::class, $this->chats = new FakeChats);
    $this->app->instance(ReplyPlanner::class, $this->planner = new FakeReplyPlanner);
    $this->user = User::factory()->create(['name' => 'John León']);

    // Marta's email about the prepayment flow, and Pablo's direct message, from the demo data.
    $this->email = InboxItem::factory()->for($this->user)->create(['source' => AlertSource::Gmail, 'external_id' => 'demo.1', 'actor_name' => 'Marta Ruiz', 'title' => 'Flujo de prepago v4']);
    $this->slack = InboxItem::factory()->for($this->user)->create(['source' => AlertSource::Slack, 'external_id' => 'demo:D02:1004.000000', 'actor_name' => 'Pablo Gil', 'title' => 'Mensaje directo', 'body' => '¿Tienes 5 minutos?']);
});

function runAlertTool(string $tool, array $arguments): TestResponse
{
    return test()->actingAs(test()->user)->postJson(route('voice.tools.run', $tool), ['arguments' => $arguments, 'timezone' => 'Europe/Madrid']);
}

test('the reply engine gets the message, who else is in it and the conversation around it', function () {
    Event::fake([InboxItemUpdated::class]);

    app(AlertFollowUp::class)->plan($this->email);
    app(AlertFollowUp::class)->plan($this->slack);

    [$email, $slack] = $this->planner->planned;

    expect($email->from)->toBe('Marta Ruiz <marta@covermanager.test>')
        ->and($email->subject)->toBe('Flujo de prepago v4: necesito tu revisión')
        ->and($email->text)->toContain('Figma')
        ->and($slack->where)->toBe('Mensaje directo')
        ->and($slack->text)->toBe('¿Tienes 5 minutos para lo del reembolso parcial?')
        ->and($slack->conversation[0]['text'])->toBe('Buenos días John.')
        ->and($this->email->refresh()->proposal)->toMatchArray(['summary' => 'Responder a Marta que lo miras hoy.', 'reply' => ['to' => 'sender', 'text' => 'Hola Marta, lo miro hoy y te digo algo.']]);

    Event::assertDispatchedTimes(InboxItemUpdated::class, 2);
});

test('alerts the user already answered, here or in the service, are resolved', function () {
    Event::fake([InboxItemUpdated::class]);
    $notice = InboxItem::factory()->for($this->user)->create(['source' => AlertSource::Calendar, 'status' => AlertStatus::Notice]);
    $this->chats->answered = ['demo:D02:1004.000000'];

    $resolved = app(AlertFollowUp::class)->checkReplies($this->user);

    expect($resolved)->toHaveCount(1)
        ->and($this->slack->refresh()->status)->toBe(AlertStatus::Resolved)
        ->and($this->slack->resolution)->toBe('replied')
        ->and($this->slack->resolved_at)->not->toBeNull()
        ->and($this->email->refresh()->status)->toBe(AlertStatus::Pending)
        ->and($notice->refresh()->status)->toBe(AlertStatus::Notice);

    Event::assertDispatched(InboxItemUpdated::class, fn (InboxItemUpdated $event) => $event->item->is($this->slack));
});

test('sending a message from here checks whether it answered an alert', function () {
    Queue::fake([CheckAlertReplies::class]);
    $action = PendingAction::factory()->for($this->user)->create(['type' => 'chat.send', 'status' => 'confirmed', 'payload' => ['message' => ['to' => 'demo:D02', 'to_label' => 'Pablo Gil', 'text' => 'Sí, dime', 'mentions' => [], 'thread_ref' => null]]]);

    (new ExecutePendingAction($action))->handle(app(Actions::class));

    expect($action->refresh()->status->value)->toBe('executed');
    Queue::assertPushed(CheckAlertReplies::class, fn (CheckAlertReplies $job) => $job->user->is($this->user));
});

test('reviewing an alert shows the message and the proposal, thinking it through if it was not yet', function () {
    runAlertTool('review_alert', ['alert_id' => $this->email->id])
        ->assertJsonPath('canvas.view', 'alerts.review')
        ->assertJsonPath('canvas.props.proposal.summary', 'Responder a Marta que lo miras hoy.')
        ->assertJsonPath('canvas.props.is_email', true)
        ->assertJsonPath('output.alert.email_id', 'demo.1')
        ->assertJsonPath('output.proposal.reply.to', 'sender')
        ->assertJsonPath('output.message', fn (string $text) => str_contains($text, 'Figma'));

    expect($this->email->refresh()->read_at)->not->toBeNull();
});

test('an alert of someone else can not be reviewed, nor a meeting reminder', function () {
    $other = InboxItem::factory()->create();
    $notice = InboxItem::factory()->for($this->user)->create(['source' => AlertSource::Calendar, 'status' => AlertStatus::Notice]);

    runAlertTool('review_alert', ['alert_id' => $other->id])->assertJsonPath('output.error', 'No encuentro ese aviso. Búscalo con list_alerts.');
    runAlertTool('review_alert', ['alert_id' => $notice->id])->assertJsonPath('output.error', fn (string $error) => str_contains($error, 'reunión'));
});

test('the person can close an alert as done or as needing nothing', function () {
    Event::fake([InboxItemUpdated::class]);

    runAlertTool('resolve_alert', ['alert_id' => $this->email->id, 'outcome' => 'done'])->assertJsonPath('output.status', 'Resuelta');
    runAlertTool('resolve_alert', ['alert_id' => $this->slack->id, 'outcome' => 'dismissed'])->assertJsonPath('output.status', 'Descartada');
    runAlertTool('resolve_alert', ['alert_id' => $this->slack->id, 'outcome' => 'done'])->assertJsonPath('output.say', 'Ese aviso ya estaba cerrado.');

    expect($this->email->refresh()->resolution)->toBe('marked_done')
        ->and($this->slack->refresh()->status)->toBe(AlertStatus::Dismissed);

    Event::assertDispatchedTimes(InboxItemUpdated::class, 2);
});

test('alerts waiting for an answer are on screen after reloading', function () {
    $this->slack->close(AlertStatus::Resolved, 'replied');

    $this->actingAs($this->user)->get(route('home'))
        ->assertInertia(fn ($page) => $page->component('home')
            ->has('alerts', 1)
            ->where('alerts.0.id', $this->email->id)
            ->where('alerts.0.status', 'pending'));
});
