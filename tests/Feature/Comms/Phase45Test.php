<?php

use App\Alerts\AlertInbox;
use App\Alerts\AlertSource;
use App\Alerts\InboxCandidate;
use App\Alerts\InboxWatcher;
use App\Alerts\InboxWatchers;
use App\Comms\Cutover\OpenItem;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Gate\ProposalStatus;
use App\Comms\People\Relation;
use App\Connections\Provider;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Connectors\Mail\MailAddress;
use App\Jobs\AssignIntent;
use App\Models\AiUsage;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsNotification;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsProposal;
use App\Models\Comms\CommsQuestion;
use App\Models\ConnectedAccount;
use App\Models\InboxItem;
use App\Models\User;
use App\Voice\AssistantProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
});

afterEach(function () {
    Carbon::setTestNow();
});

test('a switched slack account runs the pipeline once and writes no inbox item', function () {
    Config::set('comms.pipeline.slack', true);
    $user = User::factory()->create();
    $account = ConnectedAccount::factory()->for($user)->create([
        'provider' => Provider::Slack,
        'email' => 'me@example.com',
        'metadata' => ['team_id' => 'T1', 'user_id' => 'U-me'],
    ]);
    phase45Watch($account, AlertSource::Slack, 'D5', '¿Puedes revisar el contrato?');

    expect(app(AlertInbox::class)->check($account, AlertSource::Slack))->toBe([])
        ->and(InboxItem::query()->count())->toBe(0)
        ->and(CommsEvent::query()->where('type', EventType::MessageReceived)->where('source', Source::Slack)->count())->toBe(1)
        ->and(CommsEvent::query()->where('type', EventType::MessageReceived)->where('source', Source::Slack)->sole()->processed_at)->not->toBeNull();
});

test('a slack account that is not switched still assigns an intent and does not ingest', function () {
    Queue::fake([AssignIntent::class]);
    Config::set('comms.pipeline.slack', false);
    $user = User::factory()->create();
    $account = ConnectedAccount::factory()->for($user)->create([
        'provider' => Provider::Slack,
        'email' => 'me@example.com',
        'metadata' => ['team_id' => 'T1', 'user_id' => 'U-me'],
    ]);
    phase45Watch($account, AlertSource::Slack, 'D5', '¿Puedes revisar el contrato?');

    app(AlertInbox::class)->check($account, AlertSource::Slack);

    Queue::assertPushed(AssignIntent::class, 1);
    expect(CommsEvent::query()->where('type', EventType::MessageReceived)->count())->toBe(0);
});

test('a switched gmail account is ingested as one message', function () {
    Config::set('comms.pipeline.gmail', true);
    $user = User::factory()->create();
    $account = ConnectedAccount::factory()->for($user)->create([
        'provider' => Provider::Google,
        'email' => 'me@example.com',
    ]);
    phase45Watch($account, AlertSource::Gmail, 'msg-1', 'El contrato está listo', ['INBOX']);

    app(AlertInbox::class)->check($account, AlertSource::Gmail);

    expect(InboxItem::query()->count())->toBe(0)
        ->and(CommsEvent::query()->where('type', EventType::MessageReceived)->where('source', Source::Gmail)->count())->toBe(1);
});

test('the home feed shows a cut over notification and keeps the other sources', function () {
    Config::set('comms.pipeline.slack', true);
    $user = phase45Open($question = '¿Lo reclamo?');
    InboxItem::factory()->for($user)->create(['source' => AlertSource::Slack, 'title' => 'Viejo de Slack', 'status' => 'pending']);
    InboxItem::factory()->for($user)->create(['source' => AlertSource::Jira, 'title' => 'Jira sigue', 'status' => 'pending']);

    Http::preventStrayRequests(false);

    $this->actingAs($user)->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('home')
            ->where('alerts', function (mixed $alerts) use ($question): bool {
                $titles = array_column(is_array($alerts) ? $alerts : $alerts->all(), 'title');

                return in_array($question, $titles, true)
                    && in_array('Jira sigue', $titles, true)
                    && ! in_array('Viejo de Slack', $titles, true);
            })
            ->where('openComms.question', $question));

    expect(AssistantProfile::for($user, CarbonImmutable::parse('2026-11-08 12:00:00'))->instructions)->toContain($question);
});

test('the home feed leaves communications quiet while the switch is off', function () {
    Config::set('comms.pipeline.slack', false);
    $user = phase45Open('¿Lo reclamo?');

    Http::preventStrayRequests(false);

    $this->actingAs($user)->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('openComms', null)->where('alerts', function (mixed $alerts): bool {
            $titles = array_column(is_array($alerts) ? $alerts : $alerts->all(), 'title');

            return ! in_array('¿Lo reclamo?', $titles, true);
        }));
});

test('the voice can approve the open proposal as json', function () {
    app()->instance(Chats::class, new FakeChats);
    $user = User::factory()->create();
    $proposal = CommsProposal::factory()->for($user)->create([
        'target' => ['from_user' => true, 'ref' => 'slack:U-marta'],
        'payload' => ['text' => 'Te lo reclamo hoy.', 'to' => 'C1', 'to_label' => 'Marta'],
        'status' => ProposalStatus::Pending,
    ]);

    $this->actingAs($user)->postJson(route('comms.proposals.approve', $proposal))->assertOk()->assertJson(['ok' => true]);

    expect($proposal->refresh()->status)->toBe(ProposalStatus::Executed);
});

test('another person cannot dismiss a communications notification', function () {
    $notification = CommsNotification::factory()->create(['kind' => 'question', 'urgency' => 'now']);

    $this->post(route('comms.notifications.dismiss', $notification))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->postJson(route('comms.notifications.dismiss', $notification))->assertNotFound();
});

test('the cutover report prints a number for every metric', function () {
    Carbon::setTestNow('2026-11-08 12:00:00');
    $user = User::factory()->create();
    $person = CommsPerson::query()->create([
        'user_id' => $user->id,
        'display_name' => 'Marta',
        'relation' => Relation::Collaborator,
    ]);
    $message = CommsMessage::factory()->create([
        'author_person_id' => $person->id,
        'direction' => 'in',
        'flags' => ['noise' => true, 'sensitive' => false, 'urgent' => false, 'automated' => false, 'cc_only' => false],
        'occurred_at' => now(),
    ]);
    CommsEvent::factory()->for($user)->create([
        'type' => EventType::ModelDecided,
        'source' => Source::Model,
        'normalized' => ['task' => 'triage', 'output' => ['label' => 'noise'], 'event_ids' => [$message->event_id]],
        'occurred_at' => now(),
        'processed_at' => now(),
    ]);
    CommsEvent::factory()->for($user)->create([
        'type' => EventType::UserCorrected,
        'source' => Source::User,
        'normalized' => ['action' => 'close'],
        'occurred_at' => now(),
        'processed_at' => now(),
    ]);
    CommsNotification::factory()->for($user)->create(['urgency' => 'now', 'feedback' => 'not_urgent']);
    CommsProposal::factory()->for($user)->create([
        'status' => ProposalStatus::Rejected,
        'deny_reason' => 'client',
        'decided_at' => now(),
    ]);
    AiUsage::query()->create([
        'user_id' => $user->id,
        'task' => 'triage',
        'provider' => 'fake',
        'model' => 'fake',
        'status' => 'ok',
        'input_tokens' => 10,
        'output_tokens' => 5,
        'latency_ms' => 40,
        'cost' => 1.5,
        'event_ids' => [$message->event_id],
    ]);

    $this->artisan('comms:report', ['user' => $user->id, '--date' => '2026-11-08', '--triggers' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('dropped by rule: 0%')
        ->expectsOutputToContain('dropped by model: 100%')
        ->expectsOutputToContain('misses: 0')
        ->expectsOutputToContain('corrections per 100: 100')
        ->expectsOutputToContain('now not urgent: 1 of 1')
        ->expectsOutputToContain('proposals accepted: 0%')
        ->expectsOutputToContain('proposals edited: 0%')
        ->expectsOutputToContain('proposals rejected: 100%')
        ->expectsOutputToContain('proposal reasons: client 1')
        ->expectsOutputToContain('cost: 1.50')
        ->expectsOutputToContain('latency message p50: 40 p95: 40')
        ->expectsOutputToContain('latency task triage p50: 40 p95: 40')
        ->expectsOutputToContain('unmatched triggers: 0');

    expect(app(OpenItem::class)->say($user))->toBe('');
});

function phase45Watch(ConnectedAccount $account, AlertSource $source, string $channel, string $text, array $labels = ['dm']): void
{
    $externalId = $source === AlertSource::Gmail
        ? $account->id.'.'.$channel
        : $account->id.':'.$channel.':100.000100';
    $candidate = new InboxCandidate(
        source: $source,
        externalId: $externalId,
        account: (string) $account->email,
        from: new MailAddress('Marta', 'marta@example.com'),
        to: [],
        cc: [],
        subject: $source === AlertSource::Gmail ? 'El contrato' : 'Mensaje directo',
        excerpt: $text,
        receivedAt: CarbonImmutable::parse('2026-11-08 10:00:00'),
        labels: $labels,
    );
    $watcher = new class($source, [$candidate]) implements InboxWatcher
    {
        /**
         * @param  list<InboxCandidate>  $candidates
         */
        public function __construct(private AlertSource $alertSource, private array $candidates) {}

        public function source(): AlertSource
        {
            return $this->alertSource;
        }

        public function newMessages(ConnectedAccount $account): array
        {
            return $this->candidates;
        }

        public function commit(ConnectedAccount $account): void {}
    };
    $watchers = Mockery::mock(InboxWatchers::class);
    $watchers->shouldReceive('for')->andReturn($watcher);
    app()->instance(InboxWatchers::class, $watchers);
}

function phase45Open(string $text): User
{
    $user = User::factory()->create();
    $person = CommsPerson::query()->create([
        'user_id' => $user->id,
        'display_name' => 'Marta',
        'relation' => Relation::Collaborator,
    ]);
    $intent = CommsIntent::factory()->for($user)->create();
    $message = CommsMessage::factory()->create([
        'author_person_id' => $person->id,
        'source' => Source::Slack,
        'direction' => 'in',
    ]);
    CommsConversationLink::query()->create([
        'user_id' => $user->id,
        'intent_id' => $intent->id,
        'message_id' => $message->id,
        'operation' => 'open',
        'confidence' => 0.9,
        'uncertain' => false,
    ]);
    $question = CommsQuestion::factory()->for($user)->create([
        'intent_id' => $intent->id,
        'text' => $text,
    ]);
    $event = CommsEvent::factory()->for($user)->create([
        'type' => EventType::MessageReceived,
        'source' => Source::Slack,
        'processed_at' => now(),
    ]);
    CommsNotification::factory()->for($user)->create([
        'kind' => 'question',
        'urgency' => 'now',
        'subject_type' => 'question',
        'subject_id' => $question->id,
        'source_event_ids' => [$event->id],
    ]);

    return $user;
}
