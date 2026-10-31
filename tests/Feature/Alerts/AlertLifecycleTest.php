<?php

use App\Alerts\AlertFeed;
use App\Alerts\AlertFollowUp;
use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Alerts\Push\PushSender;
use App\Connectors\Calendar\CalendarReader;
use App\Connectors\Calendar\FakeCalendarReader;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Connectors\Mail\FakeMailbox;
use App\Connectors\Mail\Mailbox;
use App\Models\InboxItem;
use App\Models\ProposalOutcome;
use App\Models\PushSubscription;
use App\Models\User;
use App\Scheduling\AgendaTaskStatus;
use Carbon\CarbonImmutable;
use Tests\Support\RecordingPushSender;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::today()->setTime(10, 0));
    $this->app->instance(Chats::class, new FakeChats);
    $this->app->instance(Mailbox::class, new FakeMailbox);
    $this->app->instance(CalendarReader::class, new FakeCalendarReader);
    $this->app->instance(PushSender::class, $this->sender = new RecordingPushSender);
    $this->user = User::factory()->create(['name' => 'John León']);
});

/**
 * In the sample thread: Marta asks at 8:49, Pablo answers at 8:55 without
 * asking anything back, and Marta asks John at 8:58.
 */
test('an alert someone else settled in its thread expires on its own; one still waiting stays', function (string $ref, string $at, bool $expires) {
    $alert = InboxItem::factory()->for($this->user)->create([
        'source' => AlertSource::Slack, 'external_id' => $ref, 'thread_key' => 'demo:C01:1001.000100',
        'actor_name' => 'Marta Ruiz', 'received_at' => CarbonImmutable::today()->setTimeFromTimeString($at),
    ]);

    app(AlertFollowUp::class)->checkReplies($this->user);

    expect($alert->refresh()->status)->toBe($expires ? AlertStatus::Resolved : AlertStatus::Pending)
        ->and($alert->resolution)->toBe($expires ? 'someone_else' : null)
        ->and($alert->outcomes()->where('outcome', ProposalOutcome::EXPIRED)->value('reason'))->toBe($expires ? 'someone_else' : null);
})->with([
    'Pablo answered after it' => ['demo:C01:1001.000200', '08:49', true],
    'nobody after it' => ['demo:C01:1001.000400', '08:58', false],
]);

test('an alert asking for a call expires once the call has happened', function () {
    $alert = InboxItem::factory()->for($this->user)->create([
        'source' => AlertSource::Gmail, 'external_id' => 'demo.1', 'actor_name' => 'Marta Ruiz', 'received_at' => now()->subDay(),
        'proposal' => ['summary' => 'Llamada.', 'actions' => [['key' => 'a0', 'class' => 'call', 'confidence' => 'sure', 'summary' => 'Marta quiere una llamada.']]],
    ]);
    $this->user->agendaTasks()->create(['inbox_item_id' => $alert->id, 'origin' => 'alert', 'kind' => 'meeting', 'title' => 'Llamada con Marta', 'status' => AgendaTaskStatus::Scheduled, 'starts_at' => now()->subHour(), 'ends_at' => now()->subMinutes(30)]);

    app(AlertFollowUp::class)->checkReplies($this->user);

    expect($alert->refresh()->resolution)->toBe('call_happened')
        ->and($alert->outcomes()->sole()->outcome)->toBe(ProposalOutcome::EXPIRED);
});

test('waiting alerts come first, by nearest deadline, then those someone is blocked on, then the rest', function () {
    $alert = fn (string $title, array $proposal = [], array $extra = []) => InboxItem::factory()->for($this->user)->create(['title' => $title, 'proposal' => $proposal === [] ? null : ['summary' => 'x', 'actions' => $proposal], ...$extra]);
    $alert('Cerrado', extra: ['status' => AlertStatus::Resolved, 'received_at' => now()]);
    $alert('Sin prisa', extra: ['received_at' => now()->subMinute()]);
    $alert('Le espera', [['key' => 'a0', 'class' => 'question', 'confidence' => 'sure', 'summary' => 'Pregunta.']], ['received_at' => now()->subHours(2)]);
    $alert('Vence mañana', [['key' => 'a0', 'class' => 'task', 'confidence' => 'sure', 'summary' => 'Informe.', 'data' => ['deadline' => now()->addDay()->toDateString()]]], ['received_at' => now()->subHours(3)]);
    $alert('Vence el viernes', [['key' => 'a0', 'class' => 'task', 'confidence' => 'sure', 'summary' => 'Demo.', 'data' => ['deadline' => now()->addDays(5)->toDateString()]]], ['received_at' => now()->subHours(4)]);

    expect(array_column(app(AlertFeed::class)->for($this->user), 'title'))->toBe(['Vence mañana', 'Vence el viernes', 'Le espera', 'Sin prisa', 'Cerrado']);
});

test('a low urgency alert does not ring, and goes in the next digest once', function () {
    PushSubscription::factory()->for($this->user)->create();
    $quiet = InboxItem::factory()->for($this->user)->create(['actor_name' => 'Lucía Romero', 'relevance' => 40]);
    $urgent = InboxItem::factory()->for($this->user)->create(['actor_name' => 'Pablo Gil', 'relevance' => 40, 'proposal' => ['summary' => 'x', 'actions' => [['key' => 'a0', 'class' => 'call', 'confidence' => 'sure', 'summary' => 'Llamada.']]]]);

    expect($quiet->quiet())->toBeTrue()
        ->and($quiet->awaitsPush())->toBeFalse()
        ->and($quiet->toAlert()['quiet'])->toBeTrue()
        ->and($urgent->quiet())->toBeFalse()
        ->and($urgent->awaitsPush())->toBeTrue();

    $this->artisan('alerts:digest')->expectsOutputToContain('1 avisos en el resumen.')->assertSuccessful();

    expect($this->sender->sent)->toHaveCount(1)
        ->and(json_decode($this->sender->sent[0]['payload'], true)['notification'])->toMatchArray(['title' => '1 aviso sin prisa', 'body' => 'De Lucía Romero. Cuando tengas un momento.'])
        ->and($quiet->refresh()->payload)->toHaveKey('digested_at');

    $this->artisan('alerts:digest')->expectsOutputToContain('0 avisos en el resumen.');

    expect($this->sender->sent)->toHaveCount(1);
});
