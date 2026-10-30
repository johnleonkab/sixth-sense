<?php

use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Models\InboxItem;
use App\Models\User;

test('the assistant finds what waits for an answer, newest first, with its proposal', function () {
    $user = User::factory()->create();
    InboxItem::factory()->for($user)->create(['title' => 'Antiguo', 'received_at' => now()->subDays(2)]);
    InboxItem::factory()->for($user)->create(['title' => 'Respondido', 'status' => AlertStatus::Resolved, 'received_at' => now()->subHour()]);
    InboxItem::factory()->for($user)->create([
        'title' => 'Contrato', 'actor_name' => 'Andrés Vela', 'external_id' => '4.m9',
        'why' => 'Necesita la firma antes del viernes.', 'received_at' => now()->subMinutes(2),
        'proposal' => ['summary' => 'Responder a Andrés que lo firmas el jueves.'],
    ]);
    InboxItem::factory()->create(['title' => 'De otro usuario']);

    $response = $this->actingAs($user)
        ->postJson(route('voice.tools.run', 'list_alerts'), ['arguments' => [], 'timezone' => 'Europe/Madrid'])
        ->assertOk()
        ->assertJsonCount(2, 'output.alerts')
        ->assertJsonPath('output.alerts.0.subject', 'Contrato')
        ->assertJsonPath('output.alerts.0.from', 'Andrés Vela')
        ->assertJsonPath('output.alerts.0.why_it_matters', 'Necesita la firma antes del viernes.')
        ->assertJsonPath('output.alerts.0.proposal', 'Responder a Andrés que lo firmas el jueves.')
        ->assertJsonPath('output.alerts.0.email_id', '4.m9')
        ->assertJsonPath('output.alerts.0.status', 'Pendiente, con propuesta')
        ->assertJsonPath('output.alerts.0.received', 'hace 2 minutos')
        ->assertJsonPath('output.alerts.1.subject', 'Antiguo')
        ->assertJsonPath('canvas.view', 'openui.view')
        ->assertJsonPath('canvas.props.title', '2 pendientes de responder')
        ->assertJsonPath('canvas.props.items.0.n', 1)
        ->assertJsonPath('canvas.props.items.0.tool', 'review_alert');

    expect($response->json('canvas.props.ui'))
        ->toContain('title: "Necesita la firma antes del viernes."')
        ->toContain('eyebrow: "Gmail · Andrés Vela · Contrato"')
        ->toContain('snippet: "→ Responder a Andrés que lo firmas el jueves."')
        ->toContain('highlight: true')
        ->toBeValidOpenUi();
});

test('one alert can be found by who or what it is about', function () {
    $user = User::factory()->create();
    InboxItem::factory()->for($user)->create(['actor_name' => 'Julián Soto', 'title' => 'Mensaje directo', 'source' => AlertSource::Slack, 'external_id' => '3:D1:1.1', 'why' => 'Pregunta si llegáis al lanzamiento.']);
    InboxItem::factory()->for($user)->create(['actor_name' => 'Marta Ruiz', 'title' => 'Prepago']);

    $this->actingAs($user)
        ->postJson(route('voice.tools.run', 'list_alerts'), ['arguments' => ['query' => 'julian lanzamiento']])
        ->assertJsonCount(1, 'output.alerts')
        ->assertJsonPath('output.alerts.0.where', 'Mensaje directo')
        ->assertJsonPath('output.alerts.0.message_id', '3:D1:1.1')
        ->assertJsonPath('output.alerts.0.conversation_id', '3:D1');
});

test('it says so when nothing waits for an answer', function () {
    $user = User::factory()->create();
    InboxItem::factory()->for($user)->create(['status' => AlertStatus::Resolved, 'received_at' => now()->subHours(2)]);

    $this->actingAs($user)
        ->postJson(route('voice.tools.run', 'list_alerts'), ['arguments' => []])
        ->assertJsonPath('output.alerts', [])
        ->assertJsonPath('output.note', 'No tiene nada pendiente de responder.')
        ->assertJsonPath('canvas', null);

    $this->actingAs($user)
        ->postJson(route('voice.tools.run', 'list_alerts'), ['arguments' => ['status' => 'all']])
        ->assertJsonPath('output.alerts.0.status', 'Resuelta');
});

test('a meeting in the list opens its day in the calendar', function () {
    $user = User::factory()->create();
    InboxItem::factory()->for($user)->create(['source' => AlertSource::Calendar, 'title' => 'Prepago con Marta', 'payload' => ['starts_at' => '2026-10-31T10:00:00+01:00']]);

    $this->actingAs($user)
        ->postJson(route('voice.tools.run', 'list_alerts'), ['arguments' => ['status' => 'all'], 'timezone' => 'Europe/Madrid'])
        ->assertJsonPath('canvas.props.items.0.tool', 'show_calendar')
        ->assertJsonPath('canvas.props.items.0.args', ['start_date' => '2026-10-31']);
});
