<?php

use App\Actions\ActionStatus;
use App\Connectors\Docs\Document;
use App\Connectors\Docs\Documents;
use App\Connectors\Docs\FakeDocuments;
use App\Connectors\Mail\FakeMailbox;
use App\Connectors\Mail\Mailbox;
use App\Models\PendingAction;
use App\Models\User;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->app->instance(Documents::class, $this->documents = new FakeDocuments);
    $this->app->instance(Mailbox::class, new FakeMailbox);
    $this->user = User::factory()->create(['name' => 'John León']);
    $this->prd = 'demo.'.FakeDocuments::PREPAGO_ID;
});

function runDocsTool(string $tool, array $arguments): TestResponse
{
    return test()->actingAs(test()->user)->postJson(route('voice.tools.run', $tool), ['arguments' => $arguments, 'timezone' => 'Europe/Madrid']);
}

test('documents are listed with owner, creation and last edit', function () {
    runDocsTool('show_document_results', ['owner' => 'Marta'])
        ->assertJsonPath('canvas.view', 'docs.results')
        ->assertJsonPath('canvas.layout', 'wide')
        ->assertJsonPath('canvas.props.results.0.title', 'Flujo de prepago v4 — PRD')
        ->assertJsonPath('canvas.props.results.0.owner.name', 'Marta Ruiz')
        ->assertJsonPath('canvas.props.results.0.modified_by.name', 'Marta Ruiz')
        ->assertJsonPath('output.results.0.document_id', $this->prd);
});

test('a document opens large and its exact text goes to the assistant', function () {
    runDocsTool('open_document', ['document' => $this->prd])
        ->assertJsonPath('canvas.view', 'docs.document')
        ->assertJsonPath('canvas.layout', 'wide')
        ->assertJsonPath('canvas.props.blocks.0', ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Flujo de prepago v4']]])
        ->assertJsonPath('output.outline', ['Flujo de prepago v4', 'Propuesta', 'Riesgos'])
        ->assertJsonPath('output.can_edit', true);
});

test('a document linked in an email can be opened from its url', function () {
    $email = runDocsTool('open_email', ['email_id' => 'demo.1']);
    $url = $email->json('output.linked_documents.0');

    expect($email->json('canvas.props.documents.0.url'))->toBe($url);

    runDocsTool('open_document', ['document' => $url])->assertJsonPath('output.title', 'Flujo de prepago v4 — PRD');
});

test('proposing an edit shows a draft and changes nothing', function () {
    runDocsTool('propose_document_edit', [
        'document' => $this->prd,
        'summary' => 'Recordatorio a 48 horas',
        'edits' => [['type' => 'replace', 'find' => '24 horas', 'text' => '48 horas']],
    ])
        ->assertJsonPath('canvas.view', 'docs.edit')
        ->assertJsonPath('canvas.props.action.status', 'pending')
        ->assertJsonPath('canvas.props.changes.0.removed', '24')
        ->assertJsonPath('canvas.props.changes.0.inserted', '48')
        ->assertJsonPath('output.status', 'esperando confirmación');

    expect(PendingAction::sole()->status)->toBe(ActionStatus::Pending)
        ->and($this->documents->appliedEdits)->toBe([]);
});

test('once the person confirms, the edit reaches the document', function () {
    $proposal = runDocsTool('propose_document_edit', [
        'document' => $this->prd,
        'summary' => 'Recordatorio a 48 horas',
        'edits' => [['type' => 'replace', 'find' => '24 horas', 'text' => '48 horas']],
    ]);

    $this->actingAs($this->user)->postJson(route('actions.confirm', $proposal->json('output.pending_action_id')))->assertOk();

    expect($this->documents->find($this->user, $this->prd)->plainText())
        ->toContain('Recordatorio de pago 48 horas antes del evento.')
        ->not->toContain('24 horas');
});

test('edits anchored to text that is not unique are sent back to the assistant', function () {
    runDocsTool('propose_document_edit', [
        'document' => $this->prd,
        'summary' => 'Cambiar paso',
        'edits' => [['type' => 'delete', 'find' => 'paso']],
    ])->assertJsonPath('canvas', null)->assertJsonStructure(['output' => ['error']]);

    expect(PendingAction::count())->toBe(0);
});

test('proposing a comment shows it signed by the person', function () {
    runDocsTool('propose_document_comment', [
        'document' => $this->prd,
        'comment' => 'Me parece bien, pero confirmemos con Pagos.',
        'quote' => 'Reembolso parcial automático',
    ])
        ->assertJsonPath('canvas.view', 'docs.comment')
        ->assertJsonPath('canvas.props.author', 'John León')
        ->assertJsonPath('canvas.props.quote', 'Reembolso parcial automático');

    expect($this->documents->comments)->toBe([]);
});

test('the assistant can check what really happened to a proposal', function () {
    $proposal = runDocsTool('propose_document_comment', ['document' => $this->prd, 'comment' => 'Ok']);
    $id = $proposal->json('output.pending_action_id');

    runDocsTool('get_action_status', ['pending_action_id' => $id])->assertJsonPath('output.status', 'pending');

    $this->actingAs($this->user)->postJson(route('actions.cancel', $id));

    runDocsTool('get_action_status', ['pending_action_id' => $id])
        ->assertJsonPath('output.status', 'cancelled')
        ->assertJsonPath('output.meaning', 'Cancelado: no se ha hecho nada.')
        ->assertJsonPath('output.say', 'Cancelado, no he hecho nada.');
});

test('a comment quoting text that is not in the document is refused', function () {
    runDocsTool('propose_document_comment', ['document' => $this->prd, 'comment' => 'Ok', 'quote' => 'no existe'])
        ->assertJsonStructure(['output' => ['error']]);
});

test('a word file opens to be read, and edits to it are refused', function () {
    $this->app->instance(Documents::class, new class extends FakeDocuments
    {
        public function find(User $user, string $reference): ?Document
        {
            $document = parent::find($user, $reference);

            return $document ? new Document($document->ref, $document->account, $document->title, null, $document->blocks, $document->runs, $document->url, editable: false) : null;
        }
    });

    runDocsTool('open_document', ['document' => $this->prd])
        ->assertJsonPath('output.can_edit', false)
        ->assertJsonPath('output.note', fn (string $note) => str_contains($note, 'Word'));

    runDocsTool('propose_document_edit', ['document' => $this->prd, 'summary' => 'Quitar el recordatorio', 'edits' => [['type' => 'delete', 'find' => 'Recordatorio']]])
        ->assertJsonPath('canvas', null)
        ->assertJsonPath('output.error', fn (string $error) => str_contains($error, 'no se puede editar'));

    expect(PendingAction::count())->toBe(0);
});
