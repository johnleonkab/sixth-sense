<?php

use App\Connectors\Docs\Documents;
use App\Connectors\Docs\FakeDocuments;
use App\Connectors\Google\GoogleDocParser;
use App\Connectors\Wiki\FakeWiki;
use App\Connectors\Wiki\Wiki;
use App\Files\FindAcross;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->account = ConnectedAccount::factory()->for($this->user)->create(['email' => 'jhon@work.com']);
    $this->app->instance(Wiki::class, new FakeWiki);
});

test('a search that hits a title looks in Drive and Notion and does not list the latest', function () {
    Http::fake(['*' => function (Request $request) {
        expect($request->data()['orderBy'] ?? null)->toBeNull();

        return Http::response(['files' => [[
            'id' => 'doc1',
            'name' => 'Flujo de prepago',
            'mimeType' => 'application/vnd.google-apps.document',
            'modifiedTime' => '2026-10-07T08:00:00Z',
            'webViewLink' => 'https://docs.google.com/document/d/doc1/edit',
        ]]]);
    }]);

    $found = app(FindAcross::class)->find($this->user, ['prepago'], 'el PRD');

    expect($found['widened'])->toBeFalse()
        ->and(collect($found['files'])->pluck('title')->all())->toContain('Flujo de prepago', 'Plan de lanzamiento: prepago')
        ->and(collect($found['files'])->pluck('source')->unique()->sort()->values()->all())->toBe(['Google Drive', 'Notion']);
});

test('when no title matches it lists the latest fifty and puts the name that fits first', function () {
    Http::fake(['*' => function (Request $request) {
        if (($request->data()['orderBy'] ?? null) === 'modifiedTime desc') {
            return Http::response(['files' => [[
                'id' => 'sheet9',
                'name' => 'Cobro semanal',
                'mimeType' => 'application/vnd.google-apps.spreadsheet',
                'modifiedTime' => '2026-10-20T10:00:00Z',
                'webViewLink' => 'https://docs.google.com/spreadsheets/d/sheet9/edit',
            ]]]);
        }

        return Http::response(['files' => []]);
    }]);

    $found = app(FindAcross::class)->find($this->user, ['zzzqqq'], 'el cobro del cliente');

    expect($found['widened'])->toBeTrue()
        ->and($found['files'][0]['title'])->toBe('Cobro semanal')
        ->and($found['files'][0]['kind'])->toBe('sheet');
});

test('opening a spreadsheet shows its cells', function () {
    $this->app->instance(Documents::class, new FakeDocuments);
    Http::fake([
        'www.googleapis.com/drive/*' => Http::response([
            'id' => 'sheet1',
            'name' => 'Precios',
            'mimeType' => 'application/vnd.google-apps.spreadsheet',
            'webViewLink' => 'https://docs.google.com/spreadsheets/d/sheet1/edit',
        ]),
        'sheets.googleapis.com/*' => Http::response([
            'properties' => ['title' => 'Precios'],
            'sheets' => [[
                'properties' => ['title' => 'Tarifas'],
                'data' => [['rowData' => [
                    ['values' => [['formattedValue' => 'Plan'], ['formattedValue' => 'Precio']]],
                    ['values' => [['formattedValue' => 'Prepago'], ['formattedValue' => '12']]],
                ]]],
            ]],
        ]),
    ]);

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'open_document'), [
            'arguments' => ['document' => $this->account->id.'.sheet1'],
            'timezone' => 'Europe/Madrid',
        ])
        ->assertOk()
        ->assertJsonPath('canvas.view', 'docs.sheet')
        ->assertJsonPath('canvas.props.rows.0', ['Plan', 'Precio'])
        ->assertJsonPath('canvas.props.rows.1', ['Prepago', '12'])
        ->assertJsonPath('canvas.props.sheet', 'Tarifas');
});

test('opening a presentation shows its slides', function () {
    $this->app->instance(Documents::class, new FakeDocuments);
    Http::fake(['*' => function (Request $request) {
        $url = $request->url();

        if (str_contains($url, '/thumbnail')) {
            return Http::response(['contentUrl' => 'https://lh3.googleusercontent.com/slide.png']);
        }

        if (str_contains($url, 'slides.googleapis.com')) {
            return Http::response(['title' => 'Lanzamiento', 'slides' => [['objectId' => 'p1'], ['objectId' => 'p2']]]);
        }

        return Http::response([
            'id' => 'slide1',
            'name' => 'Lanzamiento',
            'mimeType' => 'application/vnd.google-apps.presentation',
            'webViewLink' => 'https://docs.google.com/presentation/d/slide1/edit',
        ]);
    }]);

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'open_document'), [
            'arguments' => ['document' => $this->account->id.'.slide1'],
            'timezone' => 'Europe/Madrid',
        ])
        ->assertOk()
        ->assertJsonPath('canvas.view', 'docs.slides')
        ->assertJsonPath('canvas.props.slides.0.number', 1)
        ->assertJsonPath('canvas.props.slides.1.src', 'https://lh3.googleusercontent.com/slide.png');
});

test('a doc image with a safe address is kept for the screen', function () {
    $document = (new GoogleDocParser)->parse([
        'documentId' => 'doc1',
        'title' => 'Con foto',
        'inlineObjects' => [
            'img1' => ['inlineObjectProperties' => ['embeddedObject' => ['imageProperties' => ['contentUri' => 'https://lh3.googleusercontent.com/a.png']]]],
        ],
        'body' => ['content' => [[
            'paragraph' => ['elements' => [
                ['inlineObjectElement' => ['inlineObjectId' => 'img1']],
                ['textRun' => ['content' => "Debajo\n", 'textStyle' => []]],
            ]],
        ]]],
    ], '1.doc1', 'jhon@work.com');

    expect($document->blocks[0])->toBe(['type' => 'image', 'src' => 'https://lh3.googleusercontent.com/a.png'])
        ->and($document->blocks[1]['runs'][0]['text'])->toBe('Debajo');
});

test('the files found are a numbered view whose items open in Drive or Notion', function () {
    Http::fake(['*' => Http::response(['files' => [[
        'id' => 'doc1',
        'name' => 'Flujo de prepago',
        'mimeType' => 'application/vnd.google-apps.document',
        'modifiedTime' => '2026-10-07T08:00:00Z',
        'webViewLink' => 'https://docs.google.com/document/d/doc1/edit',
    ]]])]);

    $response = $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'find_files'), ['arguments' => ['queries' => ['prepago']], 'timezone' => 'Europe/Madrid'])
        ->assertJsonPath('canvas.view', 'openui.view')
        ->assertJsonPath('canvas.props.source', 'Drive · Notion');

    $tools = collect($response->json('canvas.props.items'))->pluck('tool')->unique()->sort()->values()->all();

    expect($tools)->toBe(['open_document', 'open_notion_page'])
        ->and($response->json('canvas.props.ui'))->toContain('Text("«prepago»", "muted")')->toBeValidOpenUi();
});
