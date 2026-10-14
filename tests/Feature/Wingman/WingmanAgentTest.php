<?php

use App\Connectors\Calendar\CalendarReader;
use App\Connectors\Calendar\CalendarWriter;
use App\Connectors\Calendar\FakeCalendarReader;
use App\Connectors\Calendar\FakeCalendarWriter;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Connectors\Issues\FakeIssueTracker;
use App\Connectors\Issues\IssueTracker;
use App\Connectors\Mail\FakeMailbox;
use App\Connectors\Mail\FakeOutbox;
use App\Connectors\Mail\Mailbox;
use App\Connectors\Mail\Outbox;
use App\Connectors\Wiki\FakeWiki;
use App\Connectors\Wiki\Wiki;
use App\Events\WingmanMeetingUpdated;
use App\Jobs\WatchWingmanCall;
use App\Models\User;
use App\Models\WingmanInsight;
use App\Models\WingmanMeeting;
use App\Models\WingmanSegment;
use App\Wingman\Copilot\Detector;
use App\Wingman\Copilot\FakeDetector;
use App\Wingman\Copilot\Finding;
use App\Wingman\Copilot\GeminiDetector;
use App\Wingman\Copilot\WingmanAgent;
use App\Wingman\ExtensionTokens;
use App\Wingman\MeetingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // A Wednesday morning in Madrid.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00', 'Europe/Madrid'));
    config(['agenda.timezone' => 'Europe/Madrid']);
    Event::fake([WingmanMeetingUpdated::class]);
    $this->app->instance(CalendarReader::class, new FakeCalendarReader);
    $this->app->instance(CalendarWriter::class, new FakeCalendarWriter);
    $this->app->instance(IssueTracker::class, new FakeIssueTracker);
    $this->app->instance(Wiki::class, new FakeWiki);
    $this->app->instance(Chats::class, new FakeChats);
    $this->app->instance(Outbox::class, new FakeOutbox);
    $this->app->instance(Mailbox::class, new FakeMailbox);
    $this->app->instance(Detector::class, $this->detector = new FakeDetector);
    $this->user = User::factory()->create(['name' => 'John León', 'wingman_enabled' => true]);
    $this->meeting = WingmanMeeting::factory()->for($this->user)->create([
        'status' => MeetingStatus::Live,
        'title' => 'Revisión flujo de prepago',
        'account' => 'demo@sixth-sense.test',
        'attendees' => [
            ['name' => 'Andrés Vela', 'email' => 'andres@grupovela.test', 'response' => 'accepted'],
            ['name' => 'John León', 'email' => 'demo@sixth-sense.test', 'response' => 'accepted'],
        ],
    ]);
});

function line(WingmanMeeting $meeting, string $speaker, string $text): WingmanSegment
{
    return WingmanSegment::factory()->for($meeting, 'meeting')->create(['speaker' => $speaker, 'is_me' => $speaker === 'Tú', 'text' => $text, 'final' => true]);
}

function watch(): array
{
    return app(WingmanAgent::class)->watch(test()->meeting->refresh());
}

test('an issue whose state is asked about is looked up, with what blocks it', function () {
    line($this->meeting, 'Marta Ruiz', '¿Cómo va CPD-412? Lo necesitamos para la demo');

    [$card] = watch();

    expect($card->kind)->toBe('issue')
        ->and($card->title)->toBe('CPD-412 · As a diner, I want to see the total before choosing a table')
        ->and($card->body)->toContain('Está en To Do')->toContain('bloqueado por CPD-405')
        ->and($card->why)->toContain('¿Cómo va CPD-412?')
        ->and($card->links[0]['url'])->toContain('CPD-412')
        ->and($this->meeting->segments()->where('watched', false)->count())->toBe(0);
    Event::assertDispatched(WingmanMeetingUpdated::class);
});

test('talk of a follow-up next week finds a slot free for everyone, ready to be called', function () {
    line($this->meeting, 'Andrés Vela', 'Buscamos un hueco la semana que viene para cerrarlo');

    [$card] = watch();

    expect($card->kind)->toBe('slot')
        ->and($card->title)->toStartWith('Hueco para el seguimiento: Lunes 19 de octubre')
        ->and($card->body)->toBe('Libre para ti y para Andrés.')
        ->and($card->action['type'])->toBe('calendar.change')
        ->and($card->action['payload']['kind'])->toBe('create')
        ->and($card->action['payload']['event']['attendees'])->toBe([['name' => 'Andrés Vela', 'email' => 'andres@grupovela.test']])
        ->and($card->pending_action_id)->toBeNull();
});

test('a notion page that is mentioned is found', function () {
    line($this->meeting, 'Marta Ruiz', 'Está todo en la página de plan de lanzamiento.');

    [$card] = watch();

    expect($card->kind)->toBe('page')
        ->and($card->title)->toBe('Plan de lanzamiento: prepago')
        ->and($card->links[0]['label'])->toBe('Abrir en Notion');
});

test('when the person promises a message, it is written and ready to send by slack', function () {
    line($this->meeting, 'Tú', 'Le escribo a Marta sobre el alcance de la demo');

    [$card] = watch();

    expect($card->kind)->toBe('message')
        ->and($card->importance)->toBe('high')
        ->and($card->title)->toBe('Mensaje para Marta Ruiz, listo')
        ->and($card->action['type'])->toBe('chat.send')
        ->and($card->action['payload']['message']['to'])->toBe('demo:U01');
});

test('someone not in slack gets an email, if they are in the call; someone unknown, just the draft', function () {
    line($this->meeting, 'Tú', 'Le escribo a Andrés sobre el nuevo alcance');
    line($this->meeting, 'Tú', 'Le escribo a Zacarías sobre las facturas');
    config(['wingman.agent.max_per_reading' => 5]);

    $cards = collect(watch())->keyBy(fn (WingmanInsight $card) => $card->title);

    expect($cards['Email para Andrés Vela, listo']->action['type'])->toBe('mail.send')
        ->and($cards['Email para Andrés Vela, listo']->action['payload']['email']['to'][0]['email'])->toBe('andres@grupovela.test')
        ->and($cards['Mensaje para Zacarías']->action)->toBeNull()
        ->and($cards['Mensaje para Zacarías']->body)->toContain('No sé cómo hacérselo llegar');
});

test('the same thing is never brought twice in a call', function () {
    line($this->meeting, 'Marta Ruiz', '¿Cómo va CPD-412?');
    watch();
    $this->travel(5)->minutes();
    line($this->meeting, 'Pablo Gil', 'Lo de CPD-412 lo vemos luego');

    expect(watch())->toBe([])
        ->and($this->meeting->insights()->count())->toBe(1)
        // What was already brought is told to the detector, so it does not insist.
        ->and(end($this->detector->seen)[0]['text'])->toBe('Lo de CPD-412 lo vemos luego');
});

test('not too often: something not urgent waits after a card, something urgent does not', function () {
    line($this->meeting, 'Marta Ruiz', '¿Cómo va CPD-412?');
    watch();

    line($this->meeting, 'Marta Ruiz', 'Y la página de plan de lanzamiento.');
    expect(watch())->toBe([]);

    line($this->meeting, 'Tú', 'Le escribo a Marta sobre el alcance de la demo');
    expect(watch())->toHaveCount(1);
});

test('unsure findings, and what has nothing behind it, show nothing', function () {
    $this->app->instance(Detector::class, new class implements Detector
    {
        public function detect(WingmanMeeting $meeting, array $earlier, array $new, array $known): array
        {
            return [new Finding('issue', 'algo', 0.3, false, ['query' => 'CPD-412']), new Finding('page', 'algo', 0.9, false, ['query' => 'no existe esta página'])];
        }
    });
    line($this->meeting, 'Marta Ruiz', 'algo');

    expect(watch())->toBe([]);
});

test('lines from the extension have the call read every little while', function () {
    Queue::fake();
    $token = app(ExtensionTokens::class)->issue($this->user);
    $send = fn (string $id) => $this->withToken($token)->postJson("/api/wingman/extension/calls/{$this->meeting->id}/segments", ['segments' => [['client_id' => $id, 'speaker' => 'Marta Ruiz', 'text' => 'Hola', 'final' => true, 'spoken_at' => now()->toIso8601String()]]]);

    $send('a');
    Queue::assertPushed(WatchWingmanCall::class, 1);

    Queue::fake();
    $this->meeting->update(['watched_at' => now()]);
    $send('b');
    Queue::assertNotPushed(WatchWingmanCall::class);
});

test('gemini reads the new lines with what came before and what was already brought', function () {
    Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['findings' => [
        ['kind' => 'issue', 'quote' => '¿Cómo va CPD-412?', 'confidence' => 0.9, 'importance' => 'normal', 'query' => 'CPD-412'],
        ['kind' => 'nonsense', 'quote' => 'x', 'confidence' => 1, 'importance' => 'normal'],
    ]])]]]]]])]);
    $this->meeting->insights()->create(['kind' => 'page', 'title' => 'Plan de lanzamiento: prepago', 'dedupe_key' => 'page:x']);
    $detector = new GeminiDetector(['api_key' => 'k', 'base_url' => 'https://generativelanguage.googleapis.com', 'model' => 'm', 'thinking_level' => null, 'timeout' => 5]);

    $findings = $detector->detect($this->meeting, [['at' => '09:58', 'speaker' => 'Tú', 'is_me' => true, 'text' => 'Hola']], [['at' => '10:00', 'speaker' => 'Marta Ruiz', 'is_me' => false, 'text' => '¿Cómo va CPD-412?']], ['Plan de lanzamiento: prepago']);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->kind)->toBe('issue')
        ->and($findings[0]->details)->toBe(['query' => 'CPD-412']);
    Http::assertSent(fn (Request $request) => str_contains($request['contents'][0]['parts'][0]['text'], "Líneas NUEVAS:\n[10:00] Marta Ruiz: ¿Cómo va CPD-412?")
        && str_contains($request['contents'][0]['parts'][0]['text'], 'Ya aportado en esta reunión:')
        && str_contains($request['systemInstruction']['parts'][0]['text'], '«Tú» en las líneas es John León'));
});

test('cards can be rated and dropped, only by their owner, and dropped ones leave the bubble', function () {
    $card = WingmanInsight::factory()->for($this->meeting, 'meeting')->create();
    $other = WingmanInsight::factory()->create();

    $this->actingAs($this->user)->postJson(route('wingman.insights.feedback', $card->id), ['value' => 'up'])->assertOk()->assertJsonPath('insight.feedback', 'up');
    $this->actingAs($this->user)->postJson(route('wingman.insights.feedback', $other->id), ['value' => 'up'])->assertNotFound();
    $this->actingAs($this->user)->getJson(route('wingman.live'))->assertJsonPath('meeting.insights.0.id', $card->id);

    $this->actingAs($this->user)->postJson(route('wingman.insights.dismiss', $card->id))->assertOk();

    $this->actingAs($this->user)->getJson(route('wingman.live'))->assertJsonCount(0, 'meeting.insights');
    $this->actingAs($this->user)->get(route('wingman.show', $this->meeting->id))->assertInertia(fn ($page) => $page->where('meeting.insights.0.dismissed', true));
});

test('said in passing still finds it: a bare issue number, and a page name that is not its title', function () {
    $this->app->instance(Detector::class, new class implements Detector
    {
        public function detect(WingmanMeeting $meeting, array $earlier, array $new, array $known): array
        {
            return [
                new Finding('issue', 'el ticket ese, el 412', 0.9, true, ['query' => 'prepago 412']),
                new Finding('page', 'la página del plan', 0.9, true, ['query' => 'plan de lanzamiento de prepago']),
            ];
        }
    });
    line($this->meeting, 'Marta Ruiz', 'algo');

    $titles = collect(watch())->pluck('title')->all();

    expect($titles)->toContain('CPD-412 · As a diner, I want to see the total before choosing a table', 'Plan de lanzamiento: prepago');
});

test('a promised message without its text has it written apart; without one, it stays as a reminder', function () {
    Http::fake([
        '*' => Http::sequence()
            ->push(['candidates' => [['content' => ['parts' => [['text' => json_encode(['findings' => [['kind' => 'message', 'quote' => 'Le escribo a Marta', 'confidence' => 0.9, 'importance' => 'high', 'to' => 'Marta']]])]]]]]])
            ->push(['candidates' => [['content' => ['parts' => [['text' => json_encode(['about' => 'el alcance', 'draft' => 'Hola Marta, te resumo el alcance.'])]]]]]]),
    ]);
    $detector = new GeminiDetector(['api_key' => 'k', 'base_url' => 'https://generativelanguage.googleapis.com', 'model' => 'm', 'thinking_level' => null, 'timeout' => 5]);

    [$finding] = $detector->detect($this->meeting, [], [['at' => '10:00', 'speaker' => 'Tú', 'is_me' => true, 'text' => 'Le escribo a Marta']], []);

    expect($finding->details)->toMatchArray(['to' => 'Marta', 'about' => 'el alcance', 'draft' => 'Hola Marta, te resumo el alcance.']);
    Http::assertSentCount(2);

    $this->app->instance(Detector::class, new class implements Detector
    {
        public function detect(WingmanMeeting $meeting, array $earlier, array $new, array $known): array
        {
            return [new Finding('message', 'Le escribo a Marta', 0.9, true, ['to' => 'Marta'])];
        }
    });
    line($this->meeting, 'Tú', 'Le escribo a Marta');

    [$card] = watch();

    expect($card->title)->toBe('Prometiste escribir a Marta')->and($card->action)->toBeNull();
});
