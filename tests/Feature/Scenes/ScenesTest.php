<?php

use App\Actions\Actions;
use App\Actions\ActionStatus;
use App\Alerts\AlertFollowUp;
use App\Alerts\AlertSource;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Connectors\GitHub\GithubContents;
use App\Connectors\Mail\FakeMailbox;
use App\Connectors\Mail\FakeOutbox;
use App\Connectors\Mail\Mailbox;
use App\Connectors\Mail\Outbox;
use App\Events\SceneUpdated;
use App\Jobs\ExecutePendingAction;
use App\Jobs\RunScene;
use App\Jobs\RunSceneStep;
use App\Models\GithubRepository;
use App\Models\InboxItem;
use App\Models\PendingAction;
use App\Models\Scene;
use App\Models\SenseAgent;
use App\Models\SenseSkill;
use App\Models\User;
use App\Scenes\DeepSeekSceneText;
use App\Scenes\Kinds\StoriesFromNotes;
use App\Scenes\OpenScene;
use App\Scenes\SceneBoard;
use App\Scenes\SceneText;
use App\Scheduling\SlotFinder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecordingSceneText;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
});

test('open_scene answers with an empty canvas before the job runs', function () {
    Queue::fake();

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'open_scene'), [
            'arguments' => ['type' => 'ask_agent', 'question' => '¿Esto es una épica?'],
            'timezone' => 'Europe/Madrid',
        ])
        ->assertOk()
        ->assertJsonPath('canvas.view', 'scene.board')
        ->assertJsonPath('canvas.props.cards.0.state', 'empty')
        ->assertJsonPath('canvas.props.cards.0.text', '');

    Queue::assertPushed(RunScene::class);
    expect(Scene::query()->first()->canvas['cards'][0]['text'])->toBe('');
});

test('a preview scene broadcasts once per card as each one fills', function () {
    Event::fake([SceneUpdated::class]);

    app(OpenScene::class)->open($this->user, 'preview', []);

    Event::assertDispatchedTimes(SceneUpdated::class, 2);

    $events = Event::dispatched(SceneUpdated::class);
    expect($events[0][0]->cards[0]['state'])->toBe('ready')
        ->and($events[0][0]->cards[1]['state'])->toBe('empty')
        ->and($events[1][0]->cards[1]['state'])->toBe('ready');
});

test('asking an agent sends its briefing and preloaded skills and writes nothing', function () {
    $text = new RecordingSceneText(capableAnswers: [['answer' => 'No es una épica.']]);
    $this->app->instance(SceneText::class, $text);

    SenseSkill::factory()->for($this->user)->create(['name' => 'contexto', 'instructions' => 'Pilares.']);
    SenseAgent::factory()->for($this->user)->create([
        'slug' => 'jira-writer',
        'instructions' => 'Habla corto.',
        'skills' => ['contexto'],
    ]);

    $scene = app(OpenScene::class)->open($this->user, 'ask_agent', [
        'question' => '¿Esto es una épica?',
        'slug' => 'jira-writer',
    ]);

    expect($text->capableCalls)->toHaveCount(1)
        ->and($text->capableCalls[0]['user'])->toContain('Habla corto.')->toContain('Pilares.')
        ->and(PendingAction::query()->count())->toBe(0)
        ->and($scene->fresh()->canvas['cards'][0]['text'])->toBe('No es una épica.');
});

test('slot_then_reply does not call DeepSeek when the calendar has no slot', function () {
    $text = new RecordingSceneText;
    $this->app->instance(SceneText::class, $text);
    $this->mock(SlotFinder::class, fn ($mock) => $mock->shouldReceive('find')->once()->andReturn(null));

    $scene = app(OpenScene::class)->open($this->user, 'slot_then_reply', ['email_id' => 'demo.1']);

    expect($text->capableCalls)->toBe([])
        ->and(PendingAction::query()->count())->toBe(0)
        ->and($scene->fresh()->canvas['cards'][1]['state'])->toBe('failed')
        ->and($scene->fresh()->canvas['beside']['view'])->toBe('openui.view');
});

test('slot_then_reply drafts the reply with the free time', function () {
    $this->app->instance(Mailbox::class, new FakeMailbox);
    $this->app->instance(Outbox::class, new FakeOutbox);
    $start = CarbonImmutable::parse('2026-10-27 10:00', 'Europe/Madrid');
    $this->mock(SlotFinder::class, fn ($mock) => $mock->shouldReceive('find')->once()->andReturn([$start, $start->addMinutes(30)]));
    $text = new RecordingSceneText(capableAnswers: [['body' => '¿Te va el 2026-10-27 10:00?']]);
    $this->app->instance(SceneText::class, $text);

    $scene = app(OpenScene::class)->open($this->user, 'slot_then_reply', ['email_id' => 'demo.1']);

    expect($text->capableCalls[0]['user'])->toContain('2026-10-27 10:00')
        ->and($scene->fresh()->canvas['cards'][0]['text'])->toContain('2026-10-27 10:00')
        ->and($scene->fresh()->canvas['cards'][1]['text'])->toContain('2026-10-27 10:00')
        ->and(PendingAction::query()->where('type', 'mail.send')->count())->toBe(1);
});

test('stories_from_notes dispatches one job per title and a broken file is not proposed', function () {
    $text = new RecordingSceneText(capableAnswers: [
        ['titles' => ['Cobro al momento', 'Recibo roto']],
        ['why' => 'Hace falta', 'outcome' => 'El cobro queda', 'body' => 'Como cliente quiero cobrar.'],
        ['why' => 'Hace falta', 'outcome' => '', 'body' => 'Roto.'],
    ]);
    $this->app->instance(SceneText::class, $text);
    storyArchive($this->user);

    SenseSkill::factory()->for($this->user)->create(['name' => 'contexto', 'instructions' => 'Pilares.']);
    SenseAgent::factory()->for($this->user)->create([
        'slug' => 'jira-writer',
        'instructions' => 'Escribe historias.',
        'skills' => ['contexto'],
    ]);

    Queue::fake([RunSceneStep::class]);

    app(OpenScene::class)->open($this->user, 'stories_from_notes', [
        'notes' => 'Hay que cobrar y guardar el recibo.',
        'project' => 'prepago',
        'slug' => 'jira-writer',
    ]);

    Queue::assertPushed(RunSceneStep::class, 2);
    expect(PendingAction::query()->count())->toBe(0)
        ->and(Scene::query()->first()->canvas['cards'][0]['state'])->toBe('empty');

    foreach (Queue::pushed(RunSceneStep::class) as $job) {
        $job->handle(app(StoriesFromNotes::class), app(SceneBoard::class));
    }

    $cards = Scene::query()->first()->canvas['cards'];

    expect(PendingAction::query()->count())->toBe(1)
        ->and(collect($cards)->firstWhere('state', 'failed')['error'])->toContain('outcome')
        ->and(collect($cards)->firstWhere('state', 'ready'))->not->toBeNull();
});

test('saying yes confirms every proposal in the scene and undoing one leaves the other', function () {
    Queue::fake([ExecutePendingAction::class]);

    $actions = app(Actions::class);
    $first = $actions->propose($this->user, 'sense.write', 'Guardar una', ['path' => 'a.md', 'contents' => 'a', 'message' => 'Crea a.md']);
    $second = $actions->propose($this->user, 'sense.write', 'Guardar otra', ['path' => 'b.md', 'contents' => 'b', 'message' => 'Crea b.md']);
    $scene = Scene::factory()->for($this->user)->create(['type' => 'stories_from_notes']);
    $scene->steps()->create(['layer' => 2, 'key' => 'a', 'status' => 'done', 'payload' => [], 'pending_action_id' => $first->id]);
    $scene->steps()->create(['layer' => 2, 'key' => 'b', 'status' => 'done', 'payload' => [], 'pending_action_id' => $second->id]);

    $this->actingAs($this->user)->postJson(route('scenes.confirm', $scene))->assertOk();

    expect($first->refresh()->status)->toBe(ActionStatus::Confirmed)
        ->and($second->refresh()->status)->toBe(ActionStatus::Confirmed);

    $actions->undo($first->refresh());

    expect($first->refresh()->status)->toBe(ActionStatus::Undone)
        ->and($second->refresh()->status)->toBe(ActionStatus::Confirmed);
});

test('an alert that matches no scene kind does not open one', function () {
    $this->app->instance(Chats::class, new FakeChats);
    $this->app->instance(Mailbox::class, new FakeMailbox);

    $item = InboxItem::factory()->for($this->user)->create([
        'source' => AlertSource::Slack,
        'body' => '¿Tienes un minuto?',
    ]);

    app(AlertFollowUp::class)->plan($item);

    expect(Scene::query()->count())->toBe(0)
        ->and($item->refresh()->proposal['summary'] ?? null)->not->toBe('Hay una escena preparada en este aviso.');
});

test('a jira comment opens a scene and reviewing it does not call a model again', function () {
    $text = new RecordingSceneText(
        capableAnswers: [
            ['titles' => ['Cobro']],
            ['why' => 'Hace falta', 'outcome' => 'Cobrado', 'body' => 'Como cliente quiero cobrar.'],
        ],
        lightAnswers: [['slug' => 'jira-writer']],
    );
    $this->app->instance(SceneText::class, $text);
    storyArchive($this->user);

    SenseSkill::factory()->for($this->user)->create(['name' => 'contexto', 'instructions' => 'Pilares del cobro.']);
    SenseAgent::factory()->for($this->user)->create([
        'slug' => 'jira-writer',
        'instructions' => 'Escribe historias.',
        'skills' => ['contexto'],
    ]);

    $item = InboxItem::factory()->for($this->user)->create([
        'source' => AlertSource::Jira,
        'body' => 'En la reunión salió cobrar al momento.',
        'payload' => ['project' => 'prepago'],
    ]);

    app(AlertFollowUp::class)->plan($item);

    $calls = count($text->capableCalls);
    expect($calls)->toBeGreaterThan(0)
        ->and($item->refresh()->proposal['scene_id'])->toBe(Scene::query()->first()->id)
        ->and(Scene::query()->first()->origin)->toBe('alert')
        ->and(Scene::query()->first()->canvas['cards'][0]['text'])->toContain('cobrar');

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'review_alert'), [
            'arguments' => ['alert_id' => $item->id],
            'timezone' => 'Europe/Madrid',
        ])
        ->assertOk()
        ->assertJsonPath('canvas.view', 'scene.board')
        ->assertJsonPath('output.scene_id', $item->scene->id);

    expect(count($text->capableCalls))->toBe($calls)
        ->and(count($text->lightCalls))->toBe(1);
});

test('scene text stays on DeepSeek when the app text provider is Gemini', function () {
    config(['ai.text' => 'gemini']);

    expect(app(SceneText::class))->toBeInstanceOf(DeepSeekSceneText::class);
});

function storyArchive(User $user): void
{
    $repository = GithubRepository::factory()->writable()->create(['is_work' => true]);
    $repository->account->forceFill(['user_id' => $user->id])->save();

    $yaml = "type: prd\nwhere: projects/{project}/docs\nname: \"{date}-{slug}.md\"\nrequired:\n  author: Quién lo pide\n  why: Por qué hace falta\n  outcome: Qué se espera al terminar\n";

    test()->mock(GithubContents::class, function ($mock) use ($yaml) {
        $mock->shouldReceive('read')->andReturn(['content' => $yaml, 'sha' => 'abc']);
    });
}
