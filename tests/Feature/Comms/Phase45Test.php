<?php

use App\Alerts\AlertSource;
use App\Comms\Cutover\OpenItem;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Gate\ProposalStatus;
use App\Comms\People\Relation;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Models\AiUsage;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsNotification;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsProposal;
use App\Models\Comms\CommsQuestion;
use App\Models\InboxItem;
use App\Models\User;
use App\Voice\AssistantProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

afterEach(function () {
    Carbon::setTestNow();
});

test('the home feed shows a cut over notification and keeps the other sources', function () {
    Config::set('comms.pipeline.slack', true);
    $user = phase45Open($question = '¿Lo reclamo?', $what = 'Revisar el contrato');
    InboxItem::factory()->for($user)->create(['source' => AlertSource::Slack, 'title' => 'Viejo de Slack', 'status' => 'pending']);
    InboxItem::factory()->for($user)->create(['source' => AlertSource::Jira, 'title' => 'Jira sigue', 'status' => 'pending']);

    Http::preventStrayRequests(false);

    $this->actingAs($user)->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('home')
            ->where('alerts', function (mixed $alerts) use ($what): bool {
                $rows = is_array($alerts) ? $alerts : $alerts->all();
                $titles = array_column($rows, 'title');
                $comms = collect($rows)->firstWhere('title', $what);

                return $comms !== null
                    && $comms['actor_name'] === 'Marta'
                    && in_array('Jira sigue', $titles, true)
                    && ! in_array('Viejo de Slack', $titles, true);
            })
            ->where('openComms.question', $question));

    expect(AssistantProfile::for($user, CarbonImmutable::parse('2026-11-08 12:00:00'))->instructions)->toContain($question);
});

test('the home feed leaves communications quiet while the switch is off', function () {
    Config::set('comms.pipeline.slack', false);
    $user = phase45Open('¿Lo reclamo?', $what = 'Revisar el contrato');

    Http::preventStrayRequests(false);

    $this->actingAs($user)->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('openComms', null)->where('alerts', function (mixed $alerts) use ($what): bool {
            $titles = array_column(is_array($alerts) ? $alerts : $alerts->all(), 'title');

            return ! in_array($what, $titles, true);
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

function phase45Open(string $text, string $what = 'Revisar el contrato'): User
{
    $user = User::factory()->create();
    $person = CommsPerson::query()->create([
        'user_id' => $user->id,
        'display_name' => 'Marta',
        'relation' => Relation::Collaborator,
    ]);
    $intent = CommsIntent::factory()->for($user)->create(['what' => $what]);
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
