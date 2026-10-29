<?php

use App\Connectors\Issues\FakeIssueTracker;
use App\Connectors\Issues\IssueTracker;
use App\Connectors\Wiki\FakeWiki;
use App\Connectors\Wiki\Wiki;
use App\Jobs\SyncKnowledge;
use App\Knowledge\KnowledgeBase;
use App\Knowledge\KnowledgeSource;
use App\Knowledge\KnowledgeSync;
use App\Models\GithubRepository;
use App\Models\KnowledgeDocument;
use App\Models\SenseAgent;
use App\Models\User;
use App\Scenes\OpenScene;
use App\Scenes\SceneText;
use App\Voice\AssistantProfile;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecordingSceneText;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->app->instance(IssueTracker::class, new FakeIssueTracker);
    $this->app->instance(Wiki::class, new FakeWiki);
    $this->repository = GithubRepository::factory()->writable()->create([
        'owner' => 'jhon',
        'name' => 'sense',
        'full_name' => 'jhon/sense',
        'is_work' => true,
    ]);
    $this->user = $this->repository->account->user;
});

/**
 * @param  array<string, array{sha: string, content: string}>  $files
 */
function fakeArchive(array &$files): void
{
    Http::fake(function (Request $request) use (&$files) {
        if (str_contains($request->url(), '/git/trees/')) {
            return Http::response(['tree' => array_map(
                fn (string $path, array $file): array => ['path' => $path, 'type' => 'blob', 'sha' => $file['sha']],
                array_keys($files),
                $files,
            )]);
        }

        foreach ($files as $path => $file) {
            if (str_contains(rawurldecode($request->url()), "/contents/{$path}")) {
                return Http::response(['content' => base64_encode($file['content']), 'sha' => $file['sha']]);
            }
        }

        return Http::response([], 404);
    });
}

test('a sync copies the archive, the Jira issues and the Notion pages', function () {
    $files = [
        'projects/prepago/decisions.md' => ['sha' => 'a1', 'content' => "---\ntitle: Decisiones del prepago\n---\n\nEl reembolso parcial se devuelve en 48 horas.\n"],
        'images/logo.png' => ['sha' => 'b1', 'content' => 'binario'],
    ];
    fakeArchive($files);

    $counts = app(KnowledgeSync::class)->sync($this->user);

    $decisions = $this->user->knowledgeDocuments()->where('ref', 'projects/prepago/decisions.md')->first();
    $issue = $this->user->knowledgeDocuments()->where('source', KnowledgeSource::Jira)->where('ref', 'demo:CPD-412')->first();
    $page = $this->user->knowledgeDocuments()->where('source', KnowledgeSource::Notion)->where('ref', FakeWiki::LAUNCH)->first();

    expect($counts['archive'])->toBe(1)
        ->and($decisions->title)->toBe('Decisiones del prepago (projects/prepago/decisions.md)')
        ->and($decisions->content)->toContain('48 horas')
        ->and($decisions->url)->toBe('https://github.com/jhon/sense/blob/main/projects/prepago/decisions.md')
        ->and($issue->content)->toContain('Comentarios:')->toContain('reembolso parcial')
        ->and($page->title)->toBe('Plan de lanzamiento: prepago')
        ->and($page->content)->not->toBeEmpty();
});

test('an archive file is read again only when its sha changes, and dropped when it disappears', function () {
    $files = [
        'now.md' => ['sha' => 'v1', 'content' => "# Ahora\n\nCerrando el prepago.\n"],
        'inbox/nota.md' => ['sha' => 'n1', 'content' => "Una nota.\n"],
    ];
    fakeArchive($files);
    app(KnowledgeSync::class)->sync($this->user);

    $files['now.md'] = ['sha' => 'v2', 'content' => "# Ahora\n\nLanzando el prepago.\n"];
    unset($files['inbox/nota.md']);
    app(KnowledgeSync::class)->sync($this->user);

    $archive = $this->user->knowledgeDocuments()->where('source', KnowledgeSource::Archive)->get();

    expect($archive->pluck('ref')->all())->toBe(['now.md'])
        ->and($archive->first()->content)->toContain('Lanzando el prepago.')
        ->and($archive->first()->version)->toBe('v2');

    // Two listings, both files once, and only the changed one again.
    Http::assertSentCount(5);
});

test('the search finds by content across sources and ranks the title higher', function () {
    KnowledgeDocument::factory()->for($this->user)->create([
        'source' => KnowledgeSource::Notion,
        'ref' => 'demo:reembolsos',
        'title' => 'Política de reembolsos',
        'content' => 'Un reembolso parcial se devuelve en 48 horas.',
    ]);
    KnowledgeDocument::factory()->for($this->user)->create([
        'source' => KnowledgeSource::Jira,
        'ref' => 'demo:CPD-412',
        'title' => 'CPD-412 Ver el total antes de elegir mesa',
        'content' => 'Marta pregunta si el reembolso entra en este sprint.',
    ]);
    KnowledgeDocument::factory()->for($this->user)->create(['title' => 'Otra cosa', 'content' => 'Nada que ver.']);
    KnowledgeDocument::factory()->create(['title' => 'Reembolsos de otra persona', 'content' => 'reembolso']);

    $hits = app(KnowledgeBase::class)->search($this->user, '¿Cómo funciona el reembolso?');

    expect($hits)->toHaveCount(2)
        ->and($hits[0]['title'])->toBe('Política de reembolsos')
        ->and($hits[0]['source'])->toBe('Notion')
        ->and($hits[1]['excerpt'])->toContain('reembolso')
        ->and(app(KnowledgeBase::class)->search($this->user, 'reembolso', [KnowledgeSource::Jira]))->toHaveCount(1)
        ->and(app(KnowledgeBase::class)->search($this->user, 'cpd-412')[0]['ref'])->toBe('demo:CPD-412');
});

test('the voice searches the knowledge and reads one result whole', function () {
    $document = KnowledgeDocument::factory()->for($this->user)->create([
        'source' => KnowledgeSource::Archive,
        'ref' => 'projects/prepago/decisions.md',
        'title' => 'Decisiones del prepago',
        'content' => 'El reembolso parcial se devuelve en 48 horas.',
    ]);

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'search_knowledge'), ['arguments' => ['query' => 'reembolso parcial']])
        ->assertOk()
        ->assertJsonPath('output.results.0.id', $document->id)
        ->assertJsonPath('output.results.0.source', 'Archivo');

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'read_knowledge'), ['arguments' => ['id' => $document->id]])
        ->assertOk()
        ->assertJsonPath('output.content', 'El reembolso parcial se devuelve en 48 horas.');

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'search_knowledge'), ['arguments' => ['query' => 'vacaciones']])
        ->assertJsonPath('output.results', []);
});

test('a result that was not copied yet is read from its service when opened', function () {
    $document = KnowledgeDocument::factory()->for($this->user)->create([
        'source' => KnowledgeSource::Notion,
        'ref' => FakeWiki::LAUNCH,
        'title' => 'Plan de lanzamiento: prepago',
        'content' => null,
        'indexed_at' => null,
    ]);

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'read_knowledge'), ['arguments' => ['id' => $document->id]])
        ->assertOk()
        ->assertJsonPath('output.title', 'Plan de lanzamiento: prepago');

    expect($document->fresh()->indexed_at)->not->toBeNull()
        ->and($document->fresh()->content)->not->toBeEmpty();
});

test('another persons knowledge cannot be read', function () {
    $document = KnowledgeDocument::factory()->create();

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'read_knowledge'), ['arguments' => ['id' => $document->id]])
        ->assertJsonPath('output.error', 'Ese resultado ya no está. Busca otra vez.');
});

test('the assistant is told to search the knowledge before answering about the work', function () {
    $without = AssistantProfile::for($this->user, CarbonImmutable::parse('2026-10-29 12:00', 'UTC'));
    KnowledgeDocument::factory()->for($this->user)->create();
    $with = AssistantProfile::for($this->user, CarbonImmutable::parse('2026-10-29 12:00', 'UTC'));

    expect($without->instructions)->not->toContain('search_knowledge')
        ->and($with->instructions)->toContain('search_knowledge');
});

test('asking an agent sends the knowledge that matches the question', function () {
    $text = new RecordingSceneText(capableAnswers: [['answer' => 'En 48 horas.']]);
    $this->app->instance(SceneText::class, $text);
    SenseAgent::factory()->for($this->user)->create(['slug' => 'prepago', 'instructions' => 'Habla corto.']);
    KnowledgeDocument::factory()->for($this->user)->create([
        'title' => 'Decisiones del prepago',
        'content' => 'El reembolso parcial se devuelve en 48 horas.',
    ]);

    app(OpenScene::class)->open($this->user, 'ask_agent', [
        'question' => '¿Cuándo se devuelve un reembolso?',
        'slug' => 'prepago',
    ]);

    expect($text->capableCalls[0]['user'])->toContain('Decisiones del prepago')->toContain('48 horas');
});

test('the command queues one sync per connected person on the long worker', function () {
    Queue::fake();
    User::factory()->create();

    $this->artisan('knowledge:sync --full')->assertSuccessful();

    Queue::assertPushed(SyncKnowledge::class, 1);
    Queue::assertPushed(SyncKnowledge::class, fn (SyncKnowledge $job): bool => $job->full && $job->queue === 'context' && $job->user->is($this->user));
});
