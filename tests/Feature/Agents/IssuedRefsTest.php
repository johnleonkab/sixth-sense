<?php

use App\Connectors\Mail\FakeMailbox;
use App\Connectors\Mail\Mailbox;
use App\Models\User;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->app->instance(Mailbox::class, new FakeMailbox);
    $this->user = User::factory()->create();
});

function callTool(string $tool, array $arguments, string $origin): TestResponse
{
    return test()->actingAs(test()->user)->postJson(route('voice.tools.run', $tool), ['arguments' => $arguments, 'timezone' => 'Europe/Madrid', 'origin' => $origin]);
}

test('the voice can not open an email by an id no search handed out', function () {
    callTool('open_email', ['email_id' => 'demo.1'], 'voice')
        ->assertOk()
        ->assertJsonPath('canvas', null)
        ->assertJsonPath('output.error', fn (string $error): bool => str_contains($error, 'email_id no ha salido de ninguna búsqueda'));
});

test('an id a search handed out can be opened by the voice afterwards', function () {
    callTool('show_email_results', ['from' => 'Marta', 'pattern' => 'pre-?pago'], 'voice')->assertJsonPath('output.results.0.email_id', 'demo.1');

    callTool('open_email', ['email_id' => 'demo.1'], 'voice')->assertJsonPath('canvas.view', 'mail.message');
});

test('a click on the screen is the person\'s own, so it is not checked', function () {
    callTool('open_email', ['email_id' => 'demo.1'], 'screen')->assertJsonPath('canvas.view', 'mail.message');
});

test('ids handed out to one person are not valid for another', function () {
    callTool('show_email_results', ['from' => 'Marta', 'pattern' => 'pre-?pago'], 'voice');

    $this->user = User::factory()->create();

    callTool('open_email', ['email_id' => 'demo.1'], 'voice')->assertJsonPath('canvas', null);
});
