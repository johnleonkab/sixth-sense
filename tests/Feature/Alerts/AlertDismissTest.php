<?php

use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Alerts\InboxCandidate;
use App\Alerts\Judges\FakeRelevanceJudge;
use App\Alerts\RelevanceJudge;
use App\Alerts\Triage;
use App\Connectors\Mail\MailAddress;
use App\Events\InboxItemUpdated;
use App\Jobs\JudgeInboxItem;
use App\Jobs\PlanAlertReply;
use App\Models\InboxItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Event::fake([InboxItemUpdated::class]);
    $this->user = User::factory()->create();
});

test('an alert dismissed from the screen is closed as dismissed, and says so', function (AlertStatus $status, string $triage) {
    $item = InboxItem::factory()->for($this->user)->create(['status' => $status, 'triage' => $triage]);

    $this->actingAs($this->user)->postJson(route('alerts.dismiss', $item->id))
        ->assertOk()
        ->assertJsonPath('item.status', 'dismissed')
        ->assertJsonPath('item.state', 'dismissed')
        ->assertJsonPath('item.resolution', 'dismissed');

    expect($item->refresh()->status)->toBe(AlertStatus::Dismissed)
        ->and($item->resolved_at)->not->toBeNull();
    Event::assertDispatched(InboxItemUpdated::class);
})->with([
    'waiting for an answer' => [AlertStatus::Pending, 'important'],
    'judged unimportant' => [AlertStatus::Notice, 'routine'],
    'still being judged' => [AlertStatus::Notice, 'judging'],
]);

test('an alert already closed stays as it was, and only the owner can dismiss it', function () {
    $resolved = InboxItem::factory()->for($this->user)->create(['status' => AlertStatus::Resolved, 'resolution' => 'replied']);
    $others = InboxItem::factory()->create();

    $this->actingAs($this->user)->postJson(route('alerts.dismiss', $resolved->id))->assertJsonPath('item.state', 'resolved');
    $this->actingAs($this->user)->postJson(route('alerts.dismiss', $others->id))->assertNotFound();

    expect($resolved->refresh()->resolution)->toBe('replied');
    Event::assertNotDispatched(InboxItemUpdated::class);
});

test('dismissed while being judged, the judgement does not bring it back', function () {
    Queue::fake([PlanAlertReply::class]);
    $this->app->instance(RelevanceJudge::class, new FakeRelevanceJudge);
    $candidate = new InboxCandidate(AlertSource::Slack, '1:D5:1', 'jhon@cm.com', new MailAddress('Marta Ruiz', null), [], [], 'Mensaje directo', 'Necesito que revises el contrato urgente', CarbonImmutable::now());
    $item = InboxItem::factory()->for($this->user)->create(['source' => AlertSource::Slack, 'status' => AlertStatus::Notice, 'triage' => Triage::Judging, 'payload' => ['candidate' => $candidate->toArray()]]);

    $this->actingAs($this->user)->postJson(route('alerts.dismiss', $item->id));
    (new JudgeInboxItem($item))->handle(app(RelevanceJudge::class));

    expect($item->refresh()->status)->toBe(AlertStatus::Dismissed)
        ->and($item->triage)->toBe(Triage::Important)
        ->and($item->state())->toBe('dismissed');
    Queue::assertNotPushed(PlanAlertReply::class);
});
