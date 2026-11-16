<?php

use App\Comms\Events\Source;
use App\Comms\Models\CompletesTasks;
use App\Comms\Models\FakeCompleter;
use App\Comms\Models\ModelCompletion;
use App\Comms\Nightly\HistoryFetch;
use App\Connections\Provider;
use App\Connectors\Chat\ChatMessage;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Connectors\Mail\FakeMailbox;
use App\Connectors\Mail\MailAddress;
use App\Connectors\Mail\Mailbox;
use App\Connectors\Mail\MailMessage;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsNotification;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsPreference;
use App\Models\Comms\CommsSegment;
use App\Models\Comms\CommsWindow;
use App\Models\ConnectedAccount;
use App\Models\User;
use Carbon\CarbonImmutable;

test('a reference older than 30 days is ingested and the segment links', function () {
    [$user, $day] = historyUser();
    ConnectedAccount::factory()->for($user)->create(['provider' => Provider::Slack]);
    $marta = historyPerson($user, 'Marta');
    $asking = historyMessage($user, $marta, '¿Qué pasó con el contrato de septiembre?', $day->setTime(15, 0));
    historySegment($user, $asking, ['contrato de septiembre']);
    $oldAt = $day->subDays(40)->setTime(9, 0);
    $recentAt = $day->setTime(10, 0);
    $chats = new FakeChats;
    $chats->history = [
        new ChatMessage('T1:D9:40.1', 'T1:D9', 'Marta', 'Marta Ruiz', 'U9', 'Te envié el contrato de septiembre firmado.', $oldAt),
        new ChatMessage('T1:D9:1.1', 'T1:D9', 'Marta', 'Marta Ruiz', 'U9', 'Hoy hablamos del contrato de septiembre otra vez.', $recentAt),
    ];
    app()->instance(FakeChats::class, $chats);
    app()->instance(Chats::class, $chats);
    historyScript($asking->text);

    app(HistoryFetch::class)->run($user, $day);

    $old = CommsMessage::query()->whereBelongsTo($user)->where('text', 'Te envié el contrato de septiembre firmado.')->first();
    $event = $old instanceof CommsMessage ? CommsEvent::query()->find($old->event_id) : null;

    expect($old)->not->toBeNull()
        ->and($old->occurred_at->toDateString())->toBe($oldAt->toDateString())
        ->and($event)->not->toBeNull()
        ->and($event->normalized['backfill'])->toBeTrue()
        ->and(CommsMessage::query()->whereBelongsTo($user)->where('text', 'Hoy hablamos del contrato de septiembre otra vez.')->exists())->toBeFalse()
        ->and(CommsConversationLink::query()->where('message_id', $asking->id)->exists())->toBeTrue()
        ->and(CommsNotification::query()->whereBelongsTo($user)->where('urgency', 'now')->exists())->toBeFalse()
        ->and($chats->searches)->not->toBeEmpty()
        ->and($chats->searches[0]->before)->not->toBeNull()
        ->and($oldAt->lessThan($chats->searches[0]->before))->toBeTrue()
        ->and($recentAt->greaterThan($chats->searches[0]->before))->toBeTrue();
});

test('an email older than 30 days is ingested from the mailbox fake', function () {
    [$user, $day] = historyUser();
    ConnectedAccount::factory()->for($user)->create(['provider' => Provider::Google]);
    $marta = historyPerson($user, 'Marta');
    $asking = historyMessage($user, $marta, '¿Qué pasó con el contrato de septiembre?', $day->setTime(15, 0));
    historySegment($user, $asking, ['contrato de septiembre']);
    $oldAt = $day->subDays(45)->setTime(11, 0);
    $mailbox = new FakeMailbox;
    $mailbox->extra = [
        new MailMessage(
            ref: 'old-1',
            account: 'demo@sixth-sense.test',
            from: new MailAddress('Marta Ruiz', 'marta@covermanager.test'),
            to: [new MailAddress('John', 'john@sixth-sense.test')],
            subject: 'Contrato de septiembre',
            date: $oldAt,
            snippet: 'Adjunto el contrato de septiembre.',
            body: 'Adjunto el contrato de septiembre firmado.',
        ),
    ];
    app()->instance(Mailbox::class, $mailbox);
    historyScript($asking->text);

    app(HistoryFetch::class)->run($user, $day);

    $old = CommsMessage::query()->whereBelongsTo($user)->where('text', 'like', '%Adjunto el contrato de septiembre firmado.%')->first();

    expect($old)->not->toBeNull()
        ->and($old->source)->toBe(Source::Gmail)
        ->and(CommsEvent::query()->find($old->event_id)->normalized['backfill'])->toBeTrue()
        ->and(CommsConversationLink::query()->where('message_id', $asking->id)->exists())->toBeTrue();
});

test('a segment with nothing unresolved is not searched', function () {
    [$user, $day] = historyUser();
    ConnectedAccount::factory()->for($user)->create(['provider' => Provider::Slack]);
    $marta = historyPerson($user, 'Marta');
    $asking = historyMessage($user, $marta, 'El contrato de hoy está claro.', $day->setTime(15, 0));
    historySegment($user, $asking, []);
    $chats = new FakeChats;
    app()->instance(Chats::class, $chats);

    app(HistoryFetch::class)->run($user, $day);

    expect($chats->searches)->toBe([])
        ->and(CommsConversationLink::query()->where('message_id', $asking->id)->exists())->toBeFalse();
});

/**
 * @return array{0: User, 1: CarbonImmutable}
 */
function historyUser(): array
{
    $user = User::factory()->create();
    CommsPreference::query()->create([
        'user_id' => $user->id,
        'timezone' => 'Europe/Madrid',
        'locale' => 'es',
        'work_start' => '09:00',
        'work_end' => '18:00',
        'work_days' => [1, 2, 3, 4, 5],
    ]);

    return [$user, CarbonImmutable::parse('2026-11-16', 'Europe/Madrid')];
}

function historyPerson(User $user, string $name): CommsPerson
{
    return CommsPerson::factory()->for($user)->create(['display_name' => $name]);
}

function historyMessage(User $user, CommsPerson $author, string $text, CarbonImmutable $at): CommsMessage
{
    return CommsMessage::factory()->create([
        'author_person_id' => $author->id,
        'text' => $text,
        'direction' => 'in',
        'source' => Source::Slack,
        'channel_ref' => 'D1',
        'occurred_at' => $at,
        'event_id' => CommsEvent::factory()->create([
            'user_id' => $user->id,
            'processed_at' => $at,
        ])->id,
    ]);
}

/**
 * @param  list<string>  $refs
 */
function historySegment(User $user, CommsMessage $message, array $refs): CommsSegment
{
    $window = CommsWindow::query()->create([
        'user_id' => $user->id,
        'source' => 'slack',
        'group_ref' => 'D1',
        'kind' => 'dm',
        'message_ids' => [$message->id],
        'opened_at' => $message->occurred_at,
        'last_message_at' => $message->occurred_at,
        'state' => 'settled',
    ]);

    return CommsSegment::query()->create([
        'user_id' => $user->id,
        'window_id' => $window->id,
        'message_ids' => [$message->id],
        'new_message_ids' => [$message->id],
        'participants' => ['person:'.$message->author_person_id],
        'restated' => $message->text,
        'unresolved_refs' => $refs,
    ]);
}

function historyScript(string $quote): void
{
    $link = [
        'decision' => 'new_topic',
        'name' => 'Contrato de septiembre',
        'quote' => $quote,
        'why' => 'Apunta a algo anterior.',
    ];
    $extract = ['operations' => [[
        'op' => 'open',
        'kind' => 'request',
        'what' => 'Recuperar el contrato de septiembre',
        'quote' => $quote,
    ]]];
    $critic = [
        'addressed_to_user' => ['verdict' => 'holds'],
        'already_done' => ['verdict' => 'holds'],
        'real_ask' => ['verdict' => 'holds'],
    ];
    $fake = new FakeCompleter;
    $fake->script = [
        new ModelCompletion($link, 'scripted'),
        new ModelCompletion($link, 'scripted'),
        new ModelCompletion($link, 'scripted'),
        new ModelCompletion($extract, 'scripted'),
        new ModelCompletion($extract, 'scripted'),
        new ModelCompletion($extract, 'scripted'),
        new ModelCompletion($critic, 'scripted'),
    ];
    app()->instance(CompletesTasks::class, $fake);
}
