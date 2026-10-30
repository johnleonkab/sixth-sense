<?php

use App\Connectors\Wiki\FakeWiki;
use App\Connectors\Wiki\Wiki;
use App\Models\User;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->app->instance(Wiki::class, $this->wiki = new FakeWiki);
    $this->user = User::factory()->create(['name' => 'John León']);
});

function runNotionTool(string $tool, array $arguments = []): TestResponse
{
    return test()->actingAs(test()->user)->postJson(route('voice.tools.run', $tool), ['arguments' => $arguments, 'timezone' => 'Europe/Madrid']);
}

function confirmProposal(TestResponse $proposal): void
{
    test()->actingAs(test()->user)->postJson(route('actions.confirm', $proposal->json('output.pending_action_id')))->assertOk();
}

test('pages are found by title and shown numbered', function () {
    runNotionTool('search_notion', ['query' => 'lanzamiento'])
        ->assertJsonPath('canvas.view', 'openui.view')
        ->assertJsonPath('canvas.props.items.0.n', 1)
        ->assertJsonPath('canvas.props.items.0.args', ['page' => FakeWiki::LAUNCH])
        ->assertJsonPath('canvas.props.ui', fn (string $ui): bool => str_contains($ui, 'eyebrow: "en Producto"'))
        ->assertJsonPath('output.pages.0.page_id', FakeWiki::LAUNCH)
        ->assertJsonPath('output.pages.0.in', 'Producto');
});

test('a page opens with its content, comments and links to share, by its title too', function () {
    runNotionTool('open_notion_page', ['page' => 'Plan de lanzamiento: prepago'])
        ->assertJsonPath('canvas.view', 'notion.page')
        ->assertJsonPath('canvas.props.blocks.0', ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Plan de lanzamiento']]])
        ->assertJsonPath('canvas.props.comments.0.awaits_user', true)
        ->assertJsonPath('output.link', 'https://www.notion.so/covermanager/Plan-de-lanzamiento-prepago-1a2b3c4d000040008000000000000001')
        ->assertJsonPath('output.comments.0.on', 'Lanzamiento: 20 de octubre')
        ->assertJsonPath('output.comments.0.waiting_for_user', true)
        ->assertJsonPath('output.markdown', fn (string $markdown) => str_contains($markdown, '- [ ] Revisión de Riesgos'));

    runNotionTool('open_notion_page', ['page' => FakeWiki::ONE_ON_ONE])
        ->assertJsonPath('output.public_link', 'https://covermanager.notion.site/Notas-1-1-con-Marta-1a2b3c4d000040008000000000000002');
});

test('editing is a draft until confirmed, then the page changes', function () {
    $proposal = runNotionTool('propose_notion_edit', [
        'page' => FakeWiki::LAUNCH,
        'summary' => 'Marcar la revisión de Riesgos como hecha',
        'edits' => [
            ['type' => 'replace', 'find' => '- [ ] Revisión de Riesgos', 'text' => '- [x] Revisión de Riesgos'],
            ['type' => 'append', 'text' => '## Notas'],
        ],
    ])->assertJsonPath('canvas.view', 'docs.edit')
        ->assertJsonPath('canvas.props.source', 'Notion')
        ->assertJsonPath('canvas.props.changes.0.removed', '- [ ] Revisión de Riesgos');

    confirmProposal($proposal);

    expect($this->wiki->page($this->user, FakeWiki::LAUNCH)->markdown)
        ->toContain('- [x] Revisión de Riesgos')
        ->toEndWith("\n## Notas");
});

test('an edit anchored to text that is not there, or not unique, goes back to the assistant', function (string $find, string $error) {
    runNotionTool('propose_notion_edit', ['page' => FakeWiki::LAUNCH, 'summary' => 'x', 'edits' => [['type' => 'delete', 'find' => $find]]])
        ->assertJsonPath('canvas', null)
        ->assertJsonPath('output.error', fn (string $message) => str_contains($message, $error));
})->with([
    ['- [ ] No existe', 'No encuentro'],
    ['- [ ] ', 'aparece 2 veces'],
]);

test('a new page is a draft inside its parent until confirmed', function () {
    $proposal = runNotionTool('propose_notion_page', ['parent' => 'Plan de lanzamiento', 'title' => 'Comunicación', 'content' => "- Email a clientes\n- [ ] Post en el blog"])
        ->assertJsonPath('canvas.view', 'notion.new')
        ->assertJsonPath('canvas.props.parent', 'Plan de lanzamiento: prepago')
        ->assertJsonPath('canvas.props.blocks.1.checked', false);

    expect($this->wiki->created)->toBe([]);

    confirmProposal($proposal);

    expect($this->wiki->created)->toBe([['parent' => FakeWiki::LAUNCH, 'title' => 'Comunicación', 'markdown' => "- Email a clientes\n- [ ] Post en el blog"]]);
});

test('a comment can answer a thread by its number', function () {
    $proposal = runNotionTool('propose_notion_comment', ['page' => FakeWiki::LAUNCH, 'comment' => 'Riesgos lo cierra el jueves.', 'reply_to' => '1'])
        ->assertJsonPath('canvas.view', 'openui.view')
        ->assertJsonPath('canvas.props.title', 'Responder a Marta Ruiz en «Plan de lanzamiento: prepago»');

    expect($proposal->json('canvas.props.ui'))->toContain('Quote("Por mi parte sí, falta el ok de Riesgos.", "Pablo Gil")')->toBeValidOpenUi();

    confirmProposal($proposal);

    expect($this->wiki->comments)->toBe([['ref' => FakeWiki::LAUNCH, 'content' => 'Riesgos lo cierra el jueves.', 'thread' => 'd1']]);
});

test('an unknown page says so', function () {
    runNotionTool('open_notion_page', ['page' => 'Presupuesto 2027'])->assertJsonPath('output.error', fn (string $error) => str_contains($error, 'No encuentro ninguna página'));
});
