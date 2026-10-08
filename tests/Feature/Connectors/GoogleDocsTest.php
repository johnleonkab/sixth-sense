<?php

use App\Connections\Capability;
use App\Connectors\Docs\DocumentSearch;
use App\Connectors\Docs\EditNotPossible;
use App\Connectors\Docs\EditPlan;
use App\Connectors\Google\GoogleDocs;
use App\Models\ConnectedAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function driveFile(string $id, array $overrides = []): array
{
    return [
        'id' => $id,
        'name' => 'Flujo de prepago v4',
        'createdTime' => '2026-09-25T10:00:00Z',
        'modifiedTime' => '2026-10-07T08:00:00Z',
        'owners' => [['displayName' => 'Marta Ruiz', 'emailAddress' => 'marta@example.com']],
        'lastModifyingUser' => ['displayName' => 'Pablo Gil', 'emailAddress' => 'pablo@example.com'],
        'webViewLink' => "https://docs.google.com/document/d/{$id}/edit",
        ...$overrides,
    ];
}

function docsDocument(string $id): array
{
    return [
        'documentId' => $id, 'title' => 'PRD', 'revisionId' => 'rev-1',
        'body' => ['content' => [['paragraph' => ['elements' => [['startIndex' => 1, 'textRun' => ['content' => "Recordatorio 24 horas antes.\n"]]]]]]],
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = ConnectedAccount::factory()->for($this->user)->create(['email' => 'jhon@work.com', 'access_token' => 'work-token']);
});

test('it searches google docs in drive with every filter', function () {
    Http::fake(['*' => Http::response(['files' => [driveFile('doc1')]])]);

    $result = app(GoogleDocs::class)->search($this->user, new DocumentSearch(
        name: "prepago's",
        owner: 'marta@example.com',
        modifiedAfter: CarbonImmutable::parse('2026-10-01', 'Europe/Madrid'),
    ));

    expect($result->documents[0]->ref)->toBe("{$this->account->id}.doc1")
        ->and($result->documents[0]->owner)->toBe(['name' => 'Marta Ruiz', 'email' => 'marta@example.com'])
        ->and($result->documents[0]->lastModifiedBy['name'])->toBe('Pablo Gil');

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://www.googleapis.com/drive/v3/files?')
        && $request['q'] === "mimeType = 'application/vnd.google-apps.document' and trashed = false and name contains 'prepago\\'s' and 'marta@example.com' in owners and modifiedTime >= '2026-09-30T22:00:00'"
        && $request['orderBy'] === 'modifiedTime desc'
        && $request['corpora'] === 'allDrives');
});

test('owner names are matched here because drive can only search owners by email', function () {
    Http::fake(['*' => Http::response(['files' => [driveFile('doc1'), driveFile('doc2', ['owners' => [['displayName' => 'Andrés Vela']]])]])]);

    $result = app(GoogleDocs::class)->search($this->user, new DocumentSearch(owner: 'andres'));

    expect(array_column($result->documents, 'ref'))->toBe(["{$this->account->id}.doc2"]);
});

test('a linked document is opened with whichever account can read it', function () {
    $personal = ConnectedAccount::factory()->for($this->user)->create(['access_token' => 'personal-token']);
    Http::fake(function (Request $request) {
        return str_contains($request->header('Authorization')[0], 'personal-token')
            ? Http::response(docsDocument('1AbCdEfGhIjKlMnOpQrStUvWxYz'))
            : Http::response([], 404);
    });

    $document = app(GoogleDocs::class)->find($this->user, 'https://docs.google.com/document/d/1AbCdEfGhIjKlMnOpQrStUvWxYz/edit');

    expect($document->ref)->toBe("{$personal->id}.1AbCdEfGhIjKlMnOpQrStUvWxYz");
});

test('edits are sent as one batch that requires the revision that was proposed', function () {
    Http::fake(['*' => Http::response(['replies' => []])]);

    app(GoogleDocs::class)->applyEdits($this->user, new EditPlan(
        ref: "{$this->account->id}.doc1", title: 'PRD', revisionId: 'rev-1', changes: [],
        operations: [['type' => 'delete', 'start' => 14, 'end' => 22], ['type' => 'insert', 'index' => 14, 'text' => '48 horas']],
    ));

    Http::assertSent(fn (Request $request) => $request->url() === 'https://docs.googleapis.com/v1/documents/doc1:batchUpdate'
        && $request['writeControl'] === ['requiredRevisionId' => 'rev-1']
        && $request['requests'] === [
            ['deleteContentRange' => ['range' => ['startIndex' => 14, 'endIndex' => 22]]],
            ['insertText' => ['location' => ['index' => 14], 'text' => '48 horas']],
        ]);
});

test('an edit is refused when the document changed after the proposal', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'The required revision ID does not match the latest revision.']], 400)]);

    app(GoogleDocs::class)->applyEdits($this->user, new EditPlan("{$this->account->id}.doc1", 'PRD', 'rev-1', [], []));
})->throws(EditNotPossible::class, 'ha cambiado');

test('edits need the edit capability on that account', function () {
    $this->account->toggleCapability(Capability::DocsEdit, false);
    Http::fake();

    expect(app(GoogleDocs::class)->allows($this->user, "{$this->account->id}.doc1", Capability::DocsEdit))->toBeFalse();
    expect(fn () => app(GoogleDocs::class)->applyEdits($this->user, new EditPlan("{$this->account->id}.doc1", 'PRD', 'rev-1', [], [])))
        ->toThrow(EditNotPossible::class);

    Http::assertNothingSent();
});

test('comments are posted as the user quoting the fragment', function () {
    Http::fake(['*' => Http::response(['id' => 'c1'])]);

    $id = app(GoogleDocs::class)->addComment($this->user, "{$this->account->id}.doc1", 'Esto lo veo bien', 'paso 3');

    expect($id)->toBe('c1');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://www.googleapis.com/drive/v3/files/doc1/comments?fields=id'
        && $request['content'] === 'Esto lo veo bien'
        && $request['quotedFileContent'] === ['mimeType' => 'text/plain', 'value' => 'paso 3']);
});

/**
 * A small Word file, written like Word in Spanish does: its heading style
 * has a Spanish id, and keeps its English name.
 */
function wordFile(): string
{
    $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
    $path = tempnam(sys_get_temp_dir(), 'docx-test');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('word/document.xml', <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <w:document {$w}><w:body>
            <w:p><w:pPr><w:pStyle w:val="Ttulo1"/></w:pPr><w:r><w:t>Flujo de prepago</w:t></w:r></w:p>
            <w:p><w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">Importe </w:t></w:r><w:r><w:rPr><w:b w:val="0"/></w:rPr><w:t>total</w:t></w:r><w:hyperlink r:id="rId9"><w:r><w:t> en Figma</w:t></w:r></w:hyperlink></w:p>
            <w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>Elegir mesa</w:t></w:r></w:p>
            <w:p/>
            <w:tbl><w:tr><w:tc><w:p><w:r><w:t>Paso</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>3</w:t></w:r></w:p></w:tc></w:tr></w:tbl>
        </w:body></w:document>
        XML);
    $zip->addFromString('word/styles.xml', "<w:styles {$w}><w:style w:type=\"paragraph\" w:styleId=\"Ttulo1\"><w:name w:val=\"heading 1\"/></w:style></w:styles>");
    $zip->addFromString('word/numbering.xml', "<w:numbering {$w}><w:abstractNum w:abstractNumId=\"5\"><w:lvl w:ilvl=\"0\"><w:numFmt w:val=\"decimal\"/></w:lvl></w:abstractNum><w:num w:numId=\"1\"><w:abstractNumId w:val=\"5\"/></w:num></w:numbering>");
    $zip->addFromString('word/_rels/document.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId9" Type="hyperlink" Target="https://figma.com/x" TargetMode="External"/></Relationships>');
    $zip->close();

    return tap((string) file_get_contents($path), fn () => unlink($path));
}

test('a word file kept in drive opens read-only, formatted like a google doc', function () {
    Http::fake(function (Request $request) {
        return match (true) {
            str_starts_with($request->url(), 'https://docs.googleapis.com/') => Http::response(['error' => ['message' => 'This operation is not supported for this document.']], 400),
            ($request['alt'] ?? null) === 'media' => Http::response(wordFile()),
            default => Http::response(['id' => 'word1', 'name' => 'Prepago.docx', 'size' => '2048', 'mimeType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'webViewLink' => 'https://docs.google.com/document/d/word1/edit?rtpof=true']),
        };
    });

    $document = app(GoogleDocs::class)->find($this->user, 'https://docs.google.com/document/d/word1aaaaaaaaaaaaaaaaaaa/edit?usp=drive_fs&rtpof=true');

    expect($document->editable)->toBeFalse()
        ->and($document->title)->toBe('Prepago')
        ->and($document->url)->toBe('https://docs.google.com/document/d/word1/edit?rtpof=true')
        ->and($document->plainText())->toBe("Flujo de prepago\nImporte total en Figma\nElegir mesa\n\nPaso\n3\n")
        ->and($document->blocks)->toBe([
            ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Flujo de prepago']]],
            ['type' => 'paragraph', 'runs' => [['text' => 'Importe ', 'bold' => true], ['text' => 'total'], ['text' => ' en Figma', 'link' => 'https://figma.com/x']]],
            ['type' => 'list_item', 'level' => 0, 'ordered' => true, 'runs' => [['text' => 'Elegir mesa']]],
            ['type' => 'table', 'rows' => [[[['type' => 'paragraph', 'runs' => [['text' => 'Paso']]]], [['type' => 'paragraph', 'runs' => [['text' => '3']]]]]]],
        ]);
});

test('a document the docs api can not read, and that is not a word file, does not open', function () {
    Http::fake([
        'docs.googleapis.com/*' => Http::response([], 400),
        'www.googleapis.com/drive/*' => Http::response(['id' => 'x', 'name' => 'Hoja', 'mimeType' => 'application/vnd.ms-excel']),
    ]);

    expect(app(GoogleDocs::class)->find($this->user, 'https://docs.google.com/document/d/word1aaaaaaaaaaaaaaaaaaa/edit'))->toBeNull();

    Http::assertNotSent(fn (Request $request) => ($request['alt'] ?? null) === 'media');
});

test('open comment threads are read with their replies, oldest first', function () {
    Http::fake(['*/comments*' => Http::response(['comments' => [
        ['id' => 'c2', 'author' => ['displayName' => 'Pablo Gil', 'me' => false], 'content' => '¿Lo confirmas?', 'quotedFileContent' => ['value' => 'paso 5 &amp; riesgos'], 'createdTime' => '2026-10-08T09:00:00Z', 'resolved' => false,
            'replies' => [['id' => 'r1', 'author' => ['displayName' => 'John León', 'me' => true], 'content' => 'Sí', 'createdTime' => '2026-10-08T09:10:00Z'], ['id' => 'r2', 'author' => ['displayName' => 'Pablo Gil'], 'content' => '', 'createdTime' => '2026-10-08T09:11:00Z', 'deleted' => true]]],
        ['id' => 'c1', 'author' => ['displayName' => 'Marta Ruiz'], 'content' => 'Revisa esto', 'createdTime' => '2026-10-08T08:00:00Z'],
        ['id' => 'c0', 'author' => ['displayName' => 'Marta Ruiz'], 'content' => 'Hecho', 'createdTime' => '2026-10-07T08:00:00Z', 'resolved' => true],
    ]])]);

    $comments = app(GoogleDocs::class)->comments($this->user, "{$this->account->id}.doc1");

    expect(array_map(fn ($comment) => $comment->id, $comments))->toBe(['c1', 'c2'])
        ->and($comments[1]->quote)->toBe('paso 5 & riesgos')
        ->and($comments[1]->replies)->toHaveCount(1)
        ->and($comments[1]->awaitsUser())->toBeFalse()
        ->and($comments[0]->awaitsUser())->toBeTrue();

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://www.googleapis.com/drive/v3/files/doc1/comments?') && $request['includeDeleted'] === 'false');
});

test('a reply is posted as the user, resolving the comment when asked', function () {
    Http::fake(['*' => Http::response(['id' => 'r9'])]);

    expect(app(GoogleDocs::class)->replyToComment($this->user, "{$this->account->id}.doc1", 'c2', 'Confirmado, sigue en el paso 5.', resolve: true))->toBe('r9');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://www.googleapis.com/drive/v3/files/doc1/comments/c2/replies?fields=id'
        && $request['content'] === 'Confirmado, sigue en el paso 5.'
        && $request['action'] === 'resolve');
});
