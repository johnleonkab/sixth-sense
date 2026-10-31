<?php

use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Jobs\PlanAlertReply;
use App\Models\InboxItem;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('pending alerts can be planned again with the current strategy, by person, by id or by age', function () {
    Queue::fake([PlanAlertReply::class]);
    $user = User::factory()->create();
    $recent = InboxItem::factory()->for($user)->create(['source' => AlertSource::Slack]);
    $old = InboxItem::factory()->for($user)->create(['received_at' => now()->subDays(10)]);
    InboxItem::factory()->for($user)->create(['status' => AlertStatus::Resolved]);
    InboxItem::factory()->create();

    $this->artisan('alerts:replan', ['--user' => $user->id, '--dry' => true])->expectsOutputToContain((string) $recent->id)->assertSuccessful();
    Queue::assertNothingPushed();

    $this->artisan('alerts:replan', ['--user' => $user->id])->expectsOutputToContain('1 avisos en cola')->assertSuccessful();
    Queue::assertPushed(PlanAlertReply::class, fn (PlanAlertReply $job): bool => $job->item->is($recent));

    expect($recent->refresh()->planning)->toBeTrue();

    $this->artisan('alerts:replan', ['--id' => [$old->id]])->expectsOutputToContain('1 avisos en cola')->assertSuccessful();
    Queue::assertPushed(PlanAlertReply::class, fn (PlanAlertReply $job): bool => $job->item->is($old));
});
