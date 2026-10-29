<?php

use App\Connections\AccountStatus;
use App\Connections\Provider;
use App\Connectors\Notion\NotionMarkdown;
use App\Connectors\Notion\NotionWiki;
use App\Connectors\Wiki\WikiEdit;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const PAGE_ID = '1a2b3c4d-0000-4000-8000-000000000001';

function notionPage(array $overrides = []): array
{
    return [
        'object' => 'page', 'id' => PAGE_ID, 'url' => 'https://www.notion.so/Plan-1a2b3c4d000040008000000000000001', 'public_url' => null,
        'last_edited_time' => '2026-10-08T09:00:00.000Z', 'icon' => ['type' => 'emoji', 'emoji' => '🚀'], 'in_trash' => false,
        'properties' => ['Name' => ['type' => 'title', 'title' => [['plain_text' => 'Plan de '], ['plain_text' => 'lanzamiento']]]],
        ...$overrides,
    ];
}

function notionComment(string $id, string $discussion, string $user, string $name, string $text, string $time): array
{
    return ['object' => 'comment', 'id' => $id, 'discussion_id' => $discussion, 'created_by' => ['id' => $user], 'display_name' => ['resolved_name' => $name], 'rich_text' => [['plain_text' => $text]], 'created_time' => $time];
}

beforeEach(function () {
    config(['services.notion.client_id' => 'client', 'services.notion.client_secret' => 'secret']);
    $this->user = User::factory()->create();
    $this->account = ConnectedAccount::factory()->for($this->user)->create([
        'provider' => Provider::Notion,
        'external_account_id' => 'W1:U1',
        'email' => 'jhon@cm.com',
        'access_token' => 'ntn-old',
        'refresh_token' => 'nrt-1',
        'expires_at' => null,
        'scopes' => [],
        'metadata' => ['workspace_id' => 'W1', 'workspace_name' => 'CoverManager', 'user_id' => 'U1'],
    ]);
    $this->ref = "{$this->account->id}:".PAGE_ID;
    $this->wiki = app(NotionWiki::class);
});

test('search lists pages, newest edit first, with the api version it was written for', function () {
    Http::fake(['api.notion.com/v1/search' => Http::response(['results' => [notionPage(), notionPage(['id' => 'x', 'in_trash' => true])]])]);

    $pages = $this->wiki->search($this->user, 'plan');

    expect($pages)->toHaveCount(1)
        ->and($pages[0]->title)->toBe('Plan de lanzamiento')
        ->and($pages[0]->ref)->toBe($this->ref)
        ->and($pages[0]->icon)->toBe('🚀')
        ->and($pages[0]->workspace)->toBe('CoverManager');

    Http::assertSent(fn (Request $request) => $request->header('Notion-Version')[0] === '2026-03-11'
        && $request['query'] === 'plan'
        && $request['filter'] === ['property' => 'object', 'value' => 'page']);
});

test('the outline starts at the highest pages and keeps fewer as it goes deeper', function () {
    $child = '9f8e7d6c-0000-4000-8000-000000000002';

    Http::fake(function (Request $request) use ($child) {
        if (str_contains($request->url(), '/children')) {
            return Http::response(['results' => [[
                'type' => 'child_page',
                'id' => $child,
                'child_page' => ['title' => 'Detalle'],
            ]]]);
        }

        return Http::response(['results' => [notionPage([
            'parent' => ['type' => 'workspace', 'workspace' => true],
        ])]]);
    });

    $outline = $this->wiki->outline($this->user, [1, 1]);

    expect($outline)->toHaveCount(2)
        ->and($outline[0]['depth'])->toBe(0)
        ->and($outline[0]['page']->title)->toBe('Plan de lanzamiento')
        ->and($outline[1]['depth'])->toBe(1)
        ->and($outline[1]['page']->title)->toBe('Detalle')
        ->and($outline[1]['page']->parentTitle)->toBe('Plan de lanzamiento');
});

test('a page opens from a link, with its markdown and its links to share', function () {
    Http::fake([
        'api.notion.com/v1/pages/'.PAGE_ID.'/markdown' => Http::response(['markdown' => "# Plan\n- [ ] Revisión", 'truncated' => false]),
        'api.notion.com/v1/pages/'.PAGE_ID => Http::response(notionPage(['public_url' => 'https://cm.notion.site/Plan'])),
    ]);

    $page = $this->wiki->page($this->user, 'https://www.notion.so/covermanager/Plan-de-lanzamiento-1a2b3c4d000040008000000000000001?pvs=4');

    expect($page->ref)->toBe($this->ref)
        ->and($page->markdown)->toBe("# Plan\n- [ ] Revisión")
        ->and($page->publicUrl)->toBe('https://cm.notion.site/Plan');
});

test('comments on the page and on its blocks become threads, with what they point at', function () {
    Http::fake(function (Request $request) {
        $url = $request->url();

        return match (true) {
            str_contains($url, 'blocks/'.PAGE_ID.'/children') => Http::response(['results' => [
                ['id' => 'b1', 'type' => 'paragraph', 'paragraph' => ['rich_text' => [['plain_text' => 'Lanzamiento: 20 de octubre']]]],
                ['id' => 'b2', 'type' => 'paragraph', 'paragraph' => ['rich_text' => []]],
            ]]),
            str_contains($url, 'block_id=b1') => Http::response(['results' => [
                notionComment('c2', 'd2', 'U9', 'Marta Ruiz', '¿Llegamos al 20?', '2026-10-08T09:05:00.000Z'),
                notionComment('c3', 'd2', 'U1', 'John León', 'Sí', '2026-10-08T09:10:00.000Z'),
            ]]),
            str_contains($url, 'block_id=b2') => Http::response(['results' => []]),
            default => Http::response(['results' => [notionComment('c1', 'd1', 'U9', 'Marta Ruiz', 'Añade el plan de comunicación', '2026-10-08T08:00:00.000Z')]]),
        };
    });

    $threads = $this->wiki->comments($this->user, $this->ref);

    expect($threads)->toHaveCount(2)
        ->and($threads[0]->id)->toBe('d1')
        ->and($threads[0]->quote)->toBeNull()
        ->and($threads[0]->awaitsUser())->toBeTrue()
        ->and($threads[1]->quote)->toBe('Lanzamiento: 20 de octubre')
        ->and($threads[1]->replies[0]['mine'])->toBeTrue()
        ->and($threads[1]->awaitsUser())->toBeFalse();
});

test('edits are sent as search and replace on the markdown, appends at the end', function () {
    Http::fake(['*' => Http::response(['object' => 'page_markdown'])]);

    $this->wiki->edit($this->user, $this->ref, [
        new WikiEdit('replace', '- [ ] Revisión', '- [x] Revisión'),
        new WikiEdit('insert_after', '## Tareas', '- [ ] Comunicación'),
        new WikiEdit('append', '', '## Notas'),
    ]);

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && str_ends_with($request->url(), 'pages/'.PAGE_ID.'/markdown')
        && $request['type'] === 'update_content'
        && $request['update_content']['content_updates'] === [
            ['old_str' => '- [ ] Revisión', 'new_str' => '- [x] Revisión'],
            ['old_str' => '## Tareas', 'new_str' => "## Tareas\n- [ ] Comunicación"],
        ]);
    Http::assertSent(fn (Request $request) => $request['type'] === 'insert_content' && $request['insert_content'] === ['content' => '## Notas', 'position' => ['type' => 'end']]);
});

test('a reply goes in its thread; a new comment on the page', function () {
    Http::fake(['*' => Http::response(['id' => 'c9'])]);

    expect($this->wiki->comment($this->user, $this->ref, 'Hecho', 'd2'))->toBe('c9');
    $this->wiki->comment($this->user, $this->ref, 'Nuevo');

    Http::assertSent(fn (Request $request) => $request->data() === ['discussion_id' => 'd2', 'markdown' => 'Hecho']);
    Http::assertSent(fn (Request $request) => $request->data() === ['parent' => ['page_id' => PAGE_ID], 'markdown' => 'Nuevo']);
});

test('a page is created inside another from markdown', function () {
    Http::fake(['*' => Http::response(notionPage(['id' => 'new', 'url' => 'https://www.notion.so/new']))]);

    $created = $this->wiki->create($this->user, $this->ref, 'Notas', "- Uno\n- Dos");

    expect($created->url)->toBe('https://www.notion.so/new');
    Http::assertSent(fn (Request $request) => $request['parent'] === ['page_id' => PAGE_ID]
        && $request['properties']['title']['title'][0]['text']['content'] === 'Notas'
        && $request['markdown'] === "- Uno\n- Dos");
});

test('a refused token is renewed once with the refresh token, and the request repeated', function () {
    Http::fake(function (Request $request) {
        return match (true) {
            str_contains($request->url(), 'oauth/token') => Http::response(['access_token' => 'ntn-new', 'refresh_token' => 'nrt-2']),
            $request->header('Authorization')[0] === 'Bearer ntn-old' => Http::response(['code' => 'unauthorized'], 401),
            default => Http::response(['results' => [notionPage()]]),
        };
    });

    expect($this->wiki->search($this->user, null))->toHaveCount(1)
        ->and($this->account->refresh()->access_token)->toBe('ntn-new')
        ->and($this->account->refresh_token)->toBe('nrt-2');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'oauth/token') && $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'nrt-1');
});

test('a revoked authorisation asks to connect again', function () {
    Http::fake(fn (Request $request) => str_contains($request->url(), 'oauth/token')
        ? Http::response(['error' => 'invalid_grant'], 400)
        : Http::response(['code' => 'unauthorized'], 401));

    expect($this->wiki->search($this->user, null))->toBe([])
        ->and($this->account->refresh()->status)->toBe(AccountStatus::NeedsReconnection);
});

test('notion markdown becomes the blocks the screen shows', function () {
    $blocks = NotionMarkdown::blocks(implode("\n", [
        '# Plan',
        'Texto con **negrita**, *cursiva*, `código` y [un enlace](https://x.com) y <mention-page url="https://www.notion.so/p">Otra</mention-page>.',
        "\t- Anidado",
        '- [x] Hecho',
        '1. Primero',
        '> Cita',
        '---',
        '<page url="https://www.notion.so/hija">Hija</page>',
        '<table><tr><td>A</td><td>B</td></tr></table>',
        '```php',
        'echo 1;',
        '```',
        '<script>alert(1)</script>',
    ]));

    expect($blocks[0])->toBe(['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Plan']]])
        ->and($blocks[1]['runs'])->toBe([
            ['text' => 'Texto con '], ['text' => 'negrita', 'bold' => true], ['text' => ', '], ['text' => 'cursiva', 'italic' => true], ['text' => ', '],
            ['text' => 'código', 'code' => true], ['text' => ' y '], ['text' => 'un enlace', 'link' => 'https://x.com'], ['text' => ' y '], ['text' => 'Otra', 'link' => 'https://www.notion.so/p'], ['text' => '.'],
        ])
        ->and($blocks[2])->toMatchArray(['type' => 'list_item', 'level' => 1])
        ->and($blocks[3])->toMatchArray(['type' => 'list_item', 'checked' => true])
        ->and($blocks[4])->toMatchArray(['type' => 'list_item', 'ordered' => true])
        ->and($blocks[5]['type'])->toBe('quote')
        ->and($blocks[6]['type'])->toBe('rule')
        ->and($blocks[7]['runs'][1])->toBe(['text' => 'Hija', 'link' => 'https://www.notion.so/hija'])
        ->and($blocks[8]['rows'][0][1][0]['runs'])->toBe([['text' => 'B']])
        ->and($blocks[9])->toBe(['type' => 'code', 'text' => 'echo 1;'])
        ->and($blocks[10]['runs'])->toBe([['text' => 'alert(1)']]);
});
