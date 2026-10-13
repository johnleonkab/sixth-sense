<?php

use App\Alerts\AlertSource;
use App\Alerts\InboxCandidate;
use App\Alerts\Triage;
use App\Connectors\Mail\MailAddress;
use App\Events\InboxItemCreated;
use App\Jobs\JudgeInboxItem;
use App\Models\InboxItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;

function judgingItem(User $user, int $minutesAgo): InboxItem
{
    $candidate = new InboxCandidate(AlertSource::Slack, "1:D5:{$minutesAgo}", 'jhon@cm.com', new MailAddress('Julián Soto', null), [], [], 'Mensaje directo', 'Hola, ¿qué tal?', CarbonImmutable::now());

    $item = InboxItem::factory()->for($user)->create([
        'source' => AlertSource::Slack,
        'external_id' => $candidate->externalId,
        'triage' => Triage::Judging,
        'payload' => ['candidate' => $candidate->toArray()],
    ]);
    $item->forceFill(['updated_at' => now()->subMinutes($minutesAgo)])->saveQuietly();

    return $item;
}

test('alerts left judging are queued again, and the oldest decided by rules', function () {
    Queue::fake();
    $user = User::factory()->create();
    $fresh = judgingItem($user, 1);
    $stuck = judgingItem($user, 10);
    $forgotten = judgingItem($user, 45);

    $this->artisan('alerts:unstick')->assertSuccessful();

    Queue::assertPushed(JudgeInboxItem::class, 1);
    Queue::assertPushed(JudgeInboxItem::class, fn (JudgeInboxItem $job) => $job->item->is($stuck));
    expect($fresh->refresh()->triage)->toBe(Triage::Judging)
        ->and($forgotten->refresh()->triage)->not->toBe(Triage::Judging);
});

test('when the live connection fails, the alert event is reported and the work goes on', function () {
    Exceptions::fake();
    Broadcast::extend('down', fn () => new class extends Broadcaster
    {
        public function auth($request): mixed
        {
            return null;
        }

        public function validAuthenticationResponse($request, $result): mixed
        {
            return null;
        }

        public function broadcast(array $channels, $event, array $payload = []): void
        {
            throw new BroadcastException('Reverb is down');
        }
    });
    config(['broadcasting.connections.down' => ['driver' => 'down'], 'broadcasting.default' => 'down']);

    InboxItemCreated::dispatch(InboxItem::factory()->create());

    Exceptions::assertReported(BroadcastException::class);
});
