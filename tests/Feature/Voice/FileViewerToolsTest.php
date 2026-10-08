<?php

use App\Connectors\Mail\FakeMailbox;
use App\Connectors\Mail\Mailbox;
use App\Models\User;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->app->instance(Mailbox::class, new FakeMailbox);
    $this->user = User::factory()->create();
});

function runFileTool(string $tool, array $arguments): TestResponse
{
    return test()->actingAs(test()->user)->postJson(route('voice.tools.run', $tool), ['arguments' => $arguments, 'timezone' => 'Europe/Madrid']);
}

test('a pdf attached to an email opens in the pdf viewer, at the page asked', function () {
    runFileTool('open_email_attachment', ['email_id' => 'demo.1', 'page' => 2])
        ->assertJsonPath('canvas.view', 'pdf.viewer')
        ->assertJsonPath('canvas.layout', 'wide')
        ->assertJsonPath('canvas.props.name', 'prepago-v4.pdf')
        ->assertJsonPath('canvas.props.page', 2)
        ->assertJsonPath('canvas.props.src', '/media/mail/demo.1/0')
        ->assertJsonPath('output.note', fn (string $note) => str_contains($note, 'control_viewer'));

    $this->actingAs($this->user)->get('/media/mail/demo.1/0')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'inline; filename="prepago-v4.pdf"')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertContent(FakeMailbox::samplePdf());
});

test('attachments are picked by name or number', function (string $attachment) {
    runFileTool('open_email_attachment', ['email_id' => 'demo.2', 'attachment' => $attachment])
        ->assertJsonPath('canvas.props.name', 'Contrato_Grupo_Vela_final.pdf');
})->with(['1', 'contrato']);

test('an email without that attachment is explained to the assistant', function () {
    runFileTool('open_email_attachment', ['email_id' => 'demo.2', 'attachment' => '4'])
        ->assertJsonPath('canvas', null)
        ->assertJsonPath('output.error', fn (string $error) => str_contains($error, 'Contrato_Grupo_Vela_final.pdf'));

    runFileTool('open_email_attachment', ['email_id' => 'demo.3'])->assertJsonPath('output.error', 'Ese email no tiene adjuntos.');

    $this->actingAs($this->user)->get('/media/mail/demo.3/0')->assertNotFound();
});

test('the viewer is controlled on screen; laravel only answers when nothing is open', function () {
    runFileTool('control_viewer', ['action' => 'next_page'])
        ->assertJsonPath('canvas', null)
        ->assertJsonPath('output.error', fn (string $error) => str_contains($error, 'No hay ningún PDF'));

    runFileTool('control_viewer', ['action' => 'dance'])->assertJsonPath('output.error', fn (string $error) => str_contains($error, 'Argumentos no válidos'));
});
