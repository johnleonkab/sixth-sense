<?php

use App\Events\WingmanMeetingEnded;
use App\Events\WingmanMeetingUpdated;
use App\Jobs\CloseWingmanMeeting;
use App\Jobs\TakeWingmanNotes;
use App\Models\User;
use App\Models\WingmanMeeting;
use App\Models\WingmanSegment;
use App\Wingman\ExtensionTokens;
use App\Wingman\MeetingStatus;
use App\Wingman\Notes\FakeNoteTaker;
use App\Wingman\Notes\GeminiNoteTaker;
use App\Wingman\Notes\LiveNotes;
use App\Wingman\Notes\MeetingNotes;
use App\Wingman\Notes\NoteTaker;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Event::fake([WingmanMeetingUpdated::class, WingmanMeetingEnded::class]);
    $this->app->instance(NoteTaker::class, $this->taker = new FakeNoteTaker);
    $this->user = User::factory()->create(['name' => 'John León', 'wingman_enabled' => true]);
    $this->meeting = WingmanMeeting::factory()->for($this->user)->create(['status' => MeetingStatus::Live, 'title' => 'Revisión flujo de prepago']);
});

function said(WingmanMeeting $meeting, string $speaker, string $text, bool $final = true, int $secondsAgo = 0): WingmanSegment
{
    return WingmanSegment::factory()->for($meeting, 'meeting')->create(['speaker' => $speaker, 'is_me' => $speaker === 'Tú', 'text' => $text, 'final' => $final, 'spoken_at' => now()->subSeconds($secondsAgo)]);
}

test('finished lines go into the notes, by rules of thumb in the fake: questions, decisions and the person committing', function () {
    said($this->meeting, 'Marta Ruiz', '¿Cómo va el reembolso parcial?', secondsAgo: 30);
    said($this->meeting, 'Tú', 'Lo miro en Jira y te escribo', secondsAgo: 20);
    said($this->meeting, 'Pablo Gil', 'Vale, entonces lo cerramos el jueves', secondsAgo: 10);
    said($this->meeting, 'Marta Ruiz', 'Y otra cosa', final: false);

    expect(app(LiveNotes::class)->take($this->meeting))->toBe(3);

    $notes = MeetingNotes::fromArray($this->meeting->refresh()->notes);

    expect($notes->questions)->toBe(['¿Cómo va el reembolso parcial?'])
        ->and($notes->decisions)->toBe(['Vale, entonces lo cerramos el jueves'])
        ->and($notes->commitments)->toBe([['who' => 'Tú', 'what' => 'Lo miro en Jira y te escribo', 'when' => null]])
        ->and($notes->topics[0]['title'])->toBe('Revisión flujo de prepago')
        ->and($this->meeting->notes_updated_at)->not->toBeNull()
        ->and($this->meeting->segments()->where('noted', true)->count())->toBe(3);

    // Only what is new goes the next time, with the notes so far.
    said($this->meeting, 'Marta Ruiz', 'Perfecto');
    app(LiveNotes::class)->take($this->meeting->refresh());

    expect($this->taker->updates)->toHaveCount(2)
        ->and(array_column($this->taker->updates[1], 'text'))->toBe(['Perfecto'])
        ->and(MeetingNotes::fromArray($this->meeting->refresh()->notes)->questions)->toBe(['¿Cómo va el reembolso parcial?']);
    Event::assertDispatched(WingmanMeetingUpdated::class);
});

test('as lines arrive from the extension, notes are asked for every little while, and the meeting is closed at the end', function () {
    Queue::fake();
    $token = app(ExtensionTokens::class)->issue($this->user);
    $send = fn (string $id, bool $final = true) => $this->withToken($token)->postJson("/api/wingman/extension/calls/{$this->meeting->id}/segments", ['segments' => [['client_id' => $id, 'speaker' => 'Marta Ruiz', 'text' => 'Hola', 'final' => $final, 'spoken_at' => now()->toIso8601String()]]]);

    $send('a', final: false);
    Queue::assertNotPushed(TakeWingmanNotes::class);

    $send('a');
    Queue::assertPushed(TakeWingmanNotes::class, 1);

    // Notes taken a moment ago: not again yet.
    Queue::fake();
    $this->meeting->update(['notes_updated_at' => now()]);
    $send('b');
    Queue::assertNotPushed(TakeWingmanNotes::class);

    // At the end, closing the meeting takes the last lines into the notes.
    $this->withToken($token)->postJson("/api/wingman/extension/calls/{$this->meeting->id}/end", ['left_at' => now()->toIso8601String()])->assertOk();
    Queue::assertPushed(CloseWingmanMeeting::class, 1);
});

test('gemini gets the notes so far and the new lines, and answers the whole notes', function () {
    Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
        'summary' => 'Se revisa el reembolso parcial.',
        'topics' => [['title' => 'Reembolso parcial', 'points' => ['Depende de la pasarela']]],
        'decisions' => [],
        'questions' => ['¿Entra en el sprint?'],
        'commitments' => [['who' => 'Tú', 'what' => 'Mirar CPD-412', 'when' => 'hoy']],
    ])]]]]]])]);
    $taker = new GeminiNoteTaker(['api_key' => 'k', 'base_url' => 'https://generativelanguage.googleapis.com', 'model' => 'm', 'thinking_level' => null, 'timeout' => 5]);

    $notes = $taker->update($this->meeting, MeetingNotes::empty(), [['at' => '10:01', 'speaker' => 'Tú', 'is_me' => true, 'text' => 'Lo miro hoy']]);

    expect($notes->summary)->toBe('Se revisa el reembolso parcial.')
        ->and($notes->commitments[0])->toBe(['who' => 'Tú', 'what' => 'Mirar CPD-412', 'when' => 'hoy']);
    Http::assertSent(fn (Request $request) => str_contains($request['systemInstruction']['parts'][0]['text'], '«Tú» es John León')
        && str_contains($request['contents'][0]['parts'][0]['text'], '[10:01] Tú: Lo miro hoy'));
});

test('when the note-taker fails, the lines stay to be taken next time', function () {
    $this->app->instance(NoteTaker::class, new class implements NoteTaker
    {
        public function update(WingmanMeeting $meeting, MeetingNotes $notes, array $lines): MeetingNotes
        {
            throw new RuntimeException('Gemini is down');
        }
    });
    said($this->meeting, 'Marta Ruiz', 'Hola');

    expect(fn () => app(LiveNotes::class)->take($this->meeting))->toThrow(RuntimeException::class);
    expect($this->meeting->segments()->where('noted', false)->count())->toBe(1);
});

test('the notes are trimmed to what makes sense, whatever comes back', function () {
    $notes = MeetingNotes::fromArray([
        'summary' => '  Resumen  ',
        'topics' => [['title' => '', 'points' => ['x']], ['title' => 'Tema', 'points' => ['a', 7, '', 'b']], 'basura'],
        'questions' => array_map(fn (int $index) => "Pregunta {$index}", range(1, 40)),
        'commitments' => [['who' => 'Tú', 'what' => ''], ['what' => 'Algo']],
    ]);

    expect($notes->summary)->toBe('Resumen')
        ->and($notes->topics)->toBe([['title' => 'Tema', 'points' => ['a', 'b']]])
        ->and($notes->questions)->toHaveCount(15)
        ->and($notes->commitments)->toBe([['who' => '', 'what' => 'Algo', 'when' => null]])
        ->and($notes->count())->toBe(2 + 15 + 1);
});

test('the bubble and the meeting page show the notes', function () {
    $this->meeting->update(['notes' => ['summary' => 'Se revisa el reembolso.', 'topics' => [['title' => 'Reembolso', 'points' => ['Depende de la pasarela']]], 'decisions' => [], 'questions' => ['¿Entra en el sprint?'], 'commitments' => []]]);

    $this->actingAs($this->user)->getJson(route('wingman.live'))
        ->assertJsonPath('meeting.summary', 'Se revisa el reembolso.')
        ->assertJsonPath('meeting.note_count', 2)
        ->assertJsonPath('meeting.open_questions', ['¿Entra en el sprint?']);

    $this->actingAs($this->user)->get(route('wingman.show', $this->meeting->id))
        ->assertInertia(fn ($page) => $page->where('meeting.notes.topics.0.title', 'Reembolso'));
});
