<?php

use App\Actions\ActionStatus;
use App\Connectors\Mail\FakeMailbox;
use App\Connectors\Mail\FakeOutbox;
use App\Connectors\Mail\Mailbox;
use App\Connectors\Mail\Outbox;
use App\Connectors\Mail\RecipientResolver;
use App\Models\PendingAction;
use App\Models\User;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->app->instance(Mailbox::class, new FakeMailbox);
    $this->app->instance(Outbox::class, $this->outbox = new FakeOutbox);
    $this->user = User::factory()->create(['name' => 'John León']);
});

function runMailSendTool(string $tool, array $arguments): TestResponse
{
    return test()->actingAs(test()->user)->postJson(route('voice.tools.run', $tool), ['arguments' => $arguments, 'timezone' => 'Europe/Madrid']);
}

test('names are resolved from the people the user emails with', function () {
    $resolver = app(RecipientResolver::class);

    expect($resolver->resolve($this->user, 'marta')[0]->email)->toBe('marta@covermanager.test')
        ->and($resolver->resolve($this->user, 'pablo@covermanager.test')[0]->email)->toBe('pablo@covermanager.test')
        ->and($resolver->resolve($this->user, 'Desconocido'))->toBe([]);
});

test('a reply is shown as a draft and nothing is sent', function () {
    runMailSendTool('propose_email_reply', ['email_id' => 'demo.2', 'body' => 'Lo firmo mañana a primera hora.'])
        ->assertOk()
        ->assertJsonPath('canvas.view', 'mail.draft')
        ->assertJsonPath('canvas.props.mode', 'reply')
        ->assertJsonPath('canvas.props.to.0.email', 'andres@grupovela.test')
        ->assertJsonPath('canvas.props.cc', [])
        ->assertJsonPath('canvas.props.subject', 'Re: Contrato Grupo Vela — versión final')
        ->assertJsonPath('canvas.props.message', 'Lo firmo mañana a primera hora.')
        ->assertJsonPath('beside.view', 'mail.message')
        ->assertJsonPath('beside.props.subject', 'Contrato Grupo Vela — versión final')
        ->assertJsonPath('output.status', 'esperando confirmación');

    expect(PendingAction::sole()->type)->toBe('mail.send')
        ->and($this->outbox->sent)->toBe([]);
});

test('once the person says yes, the reply is sent to the right people', function () {
    $proposal = runMailSendTool('propose_email_reply', ['email_id' => 'demo.2', 'body' => 'Ok, lo firmo.', 'reply_all' => true]);

    $this->actingAs($this->user)->postJson(route('actions.confirm', $proposal->json('output.pending_action_id')))->assertOk();

    $sent = $this->outbox->sent[0];

    expect(PendingAction::sole()->status)->toBe(ActionStatus::Executed)
        ->and($sent->mode)->toBe('reply_all')
        ->and(array_map(fn ($a) => $a->email, $sent->to))->toBe(['andres@grupovela.test'])
        ->and(array_map(fn ($a) => $a->email, $sent->cc))->toBe(['pablo@covermanager.test'])
        ->and($sent->body)->toStartWith('Ok, lo firmo.');
});

test('a forward keeps the attachments and resolves who it goes to', function () {
    runMailSendTool('propose_email_forward', ['email_id' => 'demo.1', 'to' => ['Pablo'], 'note' => 'Pablo, mira esto.'])
        ->assertJsonPath('canvas.props.mode', 'forward')
        ->assertJsonPath('canvas.props.to.0.email', 'pablo@covermanager.test')
        ->assertJsonPath('canvas.props.subject', 'Fwd: Flujo de prepago v4: necesito tu revisión')
        ->assertJsonPath('canvas.props.attachments.0.name', 'prepago-v4.pdf')
        ->assertJsonPath('beside.view', 'mail.message')
        ->assertJsonPath('beside.props.subject', 'Flujo de prepago v4: necesito tu revisión');
});

test('a new email goes to the resolved people', function () {
    runMailSendTool('propose_new_email', ['to' => ['Marta'], 'subject' => 'Demo del jueves', 'body' => 'Hola Marta, ¿movemos la demo?'])
        ->assertJsonPath('canvas.props.mode', 'new')
        ->assertJsonPath('canvas.props.from', 'demo@sixth-sense.test')
        ->assertJsonPath('canvas.props.to.0', ['name' => 'Marta Ruiz', 'email' => 'marta@covermanager.test']);
});

test('unknown people are never guessed', function () {
    runMailSendTool('propose_new_email', ['to' => ['Fulanito'], 'subject' => 'Hola', 'body' => 'Hola'])
        ->assertJsonPath('canvas', null)
        ->assertJsonPath('output.error', 'No encuentro la dirección de «Fulanito» en su correo. Pídele el email.');

    expect(PendingAction::count())->toBe(0);
});

test('an unknown email can not be answered', function () {
    runMailSendTool('propose_email_reply', ['email_id' => 'demo.404', 'body' => 'Hola'])
        ->assertJsonStructure(['output' => ['error']]);
});
