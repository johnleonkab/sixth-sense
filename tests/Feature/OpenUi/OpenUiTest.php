<?php

use App\Actions\ActionStatus;
use App\Models\ConnectedAccount;
use App\Models\McpServer;
use App\Models\PendingAction;
use App\Models\User;
use App\Voice\AssistantProfile;
use App\Voice\OpenUi\OpenUi;
use App\Voice\Tools\Analytics\ShowAnalyticsReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\ExpectationFailedException;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
});

/**
 * A remote MCP server that answers JSON-RPC, as JSON or as a stream of events.
 *
 * @param  list<array<string, mixed>>  $tools
 */
function fakeMcpServer(array $tools, bool $stream = false, ?Closure $call = null): void
{
    Http::fake(['https://1.1.1.1/*' => function (Request $request) use ($tools, $stream, $call) {
        $message = json_decode($request->body(), true);
        $reply = fn (array $result) => $stream
            ? Http::response("event: message\ndata: ".json_encode(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $result])."\n\n", 200, ['Content-Type' => 'text/event-stream'])
            : Http::response(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $result], 200, ['Mcp-Session-Id' => 'session-1']);

        return match ($message['method']) {
            'initialize' => $reply(['protocolVersion' => '2025-06-18', 'capabilities' => ['tools' => (object) []], 'serverInfo' => ['name' => 'demo', 'version' => '1']]),
            'notifications/initialized' => Http::response('', 202),
            'tools/list' => $reply(['tools' => $tools]),
            'tools/call' => $reply($call ? $call($message['params']) : ['content' => [['type' => 'text', 'text' => 'ok']]]),
        };
    }]);
}

test('the voice is told how to compose views from the OpenUI blocks', function () {
    $prompt = OpenUi::prompt();

    expect($prompt)->toContain('show_view')->toContain('View(title: string')->toContain('LineChart(')
        ->and(AssistantProfile::for($this->user, CarbonImmutable::now())->instructions)->toContain($prompt);
});

test('a composed view is shown, and one without a root is refused', function () {
    $ui = "root = View(\"Hoy\", [m])\nm = Metric(\"Usuarios\", \"1.204\")";

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'show_view'), ['arguments' => ['title' => 'Hoy', 'source' => 'Demo', 'ui' => $ui]])
        ->assertJsonPath('canvas.view', 'openui.view')
        ->assertJsonPath('canvas.props.ui', $ui)
        ->assertJsonPath('canvas.layout', 'wide');

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'show_view'), ['arguments' => ['title' => 'Hoy', 'ui' => 'Metric("a", "b")']])
        ->assertJsonPath('output.error', fn (string $error): bool => str_contains($error, 'root = View('));
});

test('connecting an MCP server keeps its tools and which ones only read', function (bool $stream) {
    fakeMcpServer([
        ['name' => 'search_issues', 'description' => 'Busca issues.', 'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]], 'annotations' => ['readOnlyHint' => true]],
        ['name' => 'create_issue', 'description' => 'Crea un issue.', 'inputSchema' => ['type' => 'object']],
    ], $stream);

    $this->actingAs($this->user)
        ->post(route('settings.mcp.store'), ['name' => 'Linear', 'url' => 'https://1.1.1.1/mcp', 'token' => 'secreto'])
        ->assertRedirect();

    $server = $this->user->mcpServers()->sole();

    expect($server->last_error)->toBeNull()
        ->and(array_column($server->tools, 'name'))->toBe(['search_issues', 'create_issue'])
        ->and(array_column($server->tools, 'read_only'))->toBe([true, false])
        ->and($server->getRawOriginal('token'))->not->toBe('secreto');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer secreto') && $request['method'] === 'initialize');

    if (! $stream) {
        Http::assertSent(fn (Request $request) => $request['method'] === 'tools/list' && $request->hasHeader('Mcp-Session-Id', 'session-1'));
    }

    $this->get(route('settings.mcp.index'))
        ->assertInertia(fn (Assert $page) => $page->component('settings/mcp')->where('servers.0.tools.1.read_only', false)->missing('servers.0.token'));
})->with(['json' => false, 'event stream' => true]);

test('an MCP server on a private address is refused', function (string $url) {
    $this->actingAs($this->user)
        ->post(route('settings.mcp.store'), ['name' => 'Interno', 'url' => $url])
        ->assertSessionHasErrors('url');

    expect($this->user->mcpServers()->count())->toBe(0);
    Http::assertNothingSent();
})->with(['http' => 'http://1.1.1.1/mcp', 'localhost' => 'https://localhost/mcp', 'loopback' => 'https://127.0.0.1/mcp', 'private' => 'https://10.0.0.5/mcp', 'metadata' => 'https://169.254.169.254/mcp']);

test('a read-only MCP tool runs at once, and any other waits for a yes', function () {
    fakeMcpServer([], call: fn (array $params) => ['content' => [['type' => 'text', 'text' => "Llamada a {$params['name']}"]]]);
    McpServer::factory()->for($this->user)->create(['name' => 'Linear', 'url' => 'https://1.1.1.1/mcp']);

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'mcp_tools'), ['arguments' => []])
        ->assertJsonPath('output.servers.0.server', 'Linear')
        ->assertJsonPath('output.servers.0.tools.1.read_only', false);

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'mcp_call'), ['arguments' => ['server' => 'linear', 'tool' => 'search', 'arguments' => ['query' => 'prepago']]])
        ->assertJsonPath('output.result', 'Llamada a search');

    $proposal = $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'mcp_call'), ['arguments' => ['server' => 'Linear', 'tool' => 'create_record', 'arguments' => ['title' => 'Nuevo']]])
        ->assertJsonPath('canvas.view', 'mcp.call')
        ->assertJsonPath('canvas.props.arguments.title', 'Nuevo');

    $called = fn (Request $request): ?array => json_decode($request->body(), true)['params'] ?? null;

    Http::assertNotSent(fn (Request $request) => ($called($request)['name'] ?? null) === 'create_record');

    $this->actingAs($this->user)->postJson(route('actions.confirm', $proposal->json('output.pending_action_id')))->assertOk();

    expect(PendingAction::query()->find($proposal->json('output.pending_action_id'))->status)->toBe(ActionStatus::Executed);
    Http::assertSent(fn (Request $request) => ($called($request)['name'] ?? null) === 'create_record' && $called($request)['arguments']['title'] === 'Nuevo');
});

test('an Analytics report is shown as a composed view with totals, trend and detail', function () {
    ConnectedAccount::factory()->for($this->user)->create();

    Http::fake([
        'analyticsadmin.googleapis.com/*' => Http::response(['accountSummaries' => [
            ['displayName' => 'CoverManager', 'propertySummaries' => [['property' => 'properties/123', 'displayName' => 'Web CoverManager']]],
        ]]),
        'analyticsdata.googleapis.com/*' => Http::response([
            'rows' => [
                ['dimensionValues' => [['value' => '20261027']], 'metricValues' => [['value' => '120'], ['value' => '150']]],
                ['dimensionValues' => [['value' => '20261028']], 'metricValues' => [['value' => '140'], ['value' => '171']]],
            ],
            'totals' => [['metricValues' => [['value' => '260'], ['value' => '321']]]],
        ]),
    ]);

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'analytics_report'), ['arguments' => ['start_date' => '7daysAgo']])
        ->assertJsonPath('output.property', 'Web CoverManager')
        ->assertJsonPath('output.totals.0', 'Usuarios activos: 260')
        ->assertJsonPath('canvas.view', 'openui.view')
        ->assertJsonPath('canvas.props.source', 'Google Analytics')
        ->assertJsonPath('canvas.props.ui', fn (string $ui): bool => str_starts_with($ui, 'root = View(')
            && str_contains($ui, 'Metric("Usuarios activos", "260")')
            && str_contains($ui, 'LineChart("Usuarios activos por día"')
            && str_contains($ui, 'Table(["Día", "Usuarios activos", "Sesiones"]'));

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'properties/123:runReport')
        && $request['dateRanges'][0]['startDate'] === '7daysAgo'
        && $request['dimensions'][0]['name'] === 'date');
});

test('with several Analytics properties and none named, the voice is asked to choose', function () {
    ConnectedAccount::factory()->for($this->user)->create();
    Http::fake(['analyticsadmin.googleapis.com/*' => Http::response(['accountSummaries' => [
        ['displayName' => 'CoverManager', 'propertySummaries' => [['property' => 'properties/1', 'displayName' => 'Web'], ['property' => 'properties/2', 'displayName' => 'App']]],
    ]])]);

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'analytics_report'), ['arguments' => []])
        ->assertJsonPath('output.choose_property', ['Web (cuenta CoverManager)', 'App (cuenta CoverManager)'])
        ->assertJsonPath('canvas', null);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'runReport'));
});

test('when Google refuses to list the Analytics properties, the voice is told why', function () {
    ConnectedAccount::factory()->for($this->user)->create();
    Http::fake(['analyticsadmin.googleapis.com/*' => Http::response(['error' => [
        'code' => 403,
        'status' => 'PERMISSION_DENIED',
        'message' => 'Google Analytics Admin API has not been used in project 1 before or it is disabled.',
        'details' => [['reason' => 'SERVICE_DISABLED']],
    ]], 403)]);

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'analytics_report'), ['arguments' => []])
        ->assertJsonPath('output.error', fn (string $error): bool => str_contains($error, 'no está activada'));
});

test('a property is found however the person names it: property, account, both or id', function (string $wanted, ?string $expected) {
    $properties = [
        ['property' => 'properties/560992337', 'name' => 'Zenchef / CoverManager (Tickets)', 'account' => 'Zenchef / CM Tickets / Experiences', 'account_id' => 1],
        ['property' => 'properties/111111111', 'name' => 'Zenchef / CoverManager (Restaurantes)', 'account' => 'Zenchef / CoverManager', 'account_id' => 1],
        ['property' => 'properties/222222222', 'name' => 'Web corporativa', 'account' => 'CoverManager', 'account_id' => 1],
    ];

    $found = ShowAnalyticsReport::closest($properties, $wanted);

    expect($expected === null ? $found : array_column($found, 'property'))->toBe($expected === null ? [] : [$expected]);
})->with([
    'property' => ['Zenchef / CoverManager (Tickets)', 'properties/560992337'],
    'account' => ['Zenchef / CM Tickets / Experiences', 'properties/560992337'],
    'both' => ['Zenchef / CoverManager (Tickets) (Zenchef / CM Tickets / Experiences)', 'properties/560992337'],
    'spoken' => ['zenchef tickets', 'properties/560992337'],
    'id' => ['560992337', 'properties/560992337'],
    'another' => ['la web corporativa', 'properties/222222222'],
    'nothing alike' => ['tienda online', null],
]);

test('the program check fails on OpenUI Lang the canvas library cannot read', function () {
    expect(fn () => expect("root = View(\"Hoy\", [x])\nx = Nope(\"a\")")->toBeValidOpenUi())->toThrow(ExpectationFailedException::class);

    expect("root = View(\"Hoy\", [x])\nx = ItemList([{n: 1, title: \"Uno\", highlight: true}], \"Nada\")")->toBeValidOpenUi();
})->skip(fn (): bool => trim((string) shell_exec('command -v node')) === '', 'Node is not installed.');

test('a list Laravel built keeps opening its items when the assistant rewrites the view', function () {
    $search = $this->actingAs($this->user)->postJson(route('voice.tools.run', 'show_view'), ['arguments' => ['title' => 'x', 'ui' => 'root = View("x", [])']]);
    expect($search->json('canvas.props.items'))->toBe([]);

    OpenUi::rememberItems($this->user, [['n' => 2, 'title' => 'CPD-405', 'tool' => 'open_jira_issue', 'args' => ['issue' => 'demo:CPD-405']]]);

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'show_view'), ['arguments' => ['title' => 'Solo en curso', 'ui' => "root = View(\"Solo en curso\", [l])\nl = ItemList([{n: 2, title: \"Integrar la pasarela\"}])"]])
        ->assertJsonPath('canvas.props.items.0.args.issue', 'demo:CPD-405');
});

test('a composed view is saved by name, opened again another day, and forgotten', function () {
    $ui = "root = View(\"Jira\", [l])\nl = ItemList([{n: 1, title: \"Integrar la pasarela\"}])";
    OpenUi::rememberItems($this->user, [['n' => 1, 'title' => 'CPD-405', 'tool' => 'open_jira_issue', 'args' => ['issue' => 'demo:CPD-405']]]);

    $this->actingAs($this->user)->postJson(route('voice.tools.run', 'show_view'), ['arguments' => ['title' => 'Jira', 'source' => 'Jira', 'ui' => $ui]]);

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'save_view'), ['arguments' => ['name' => 'Mi lunes']])
        ->assertJsonPath('output.saved_as', 'Mi lunes')
        ->assertJsonPath('output.replaced', false);

    Cache::flush();
    $this->travel(3)->days();

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'open_view'), ['arguments' => ['name' => 'mi lunes'], 'timezone' => 'Europe/Madrid'])
        ->assertJsonPath('canvas.view', 'openui.view')
        ->assertJsonPath('canvas.props.ui', $ui)
        ->assertJsonPath('canvas.props.saved_as', 'Mi lunes')
        ->assertJsonPath('canvas.props.items.0.args.issue', 'demo:CPD-405');

    expect(OpenUi::lastItems($this->user)[0]['args']['issue'])->toBe('demo:CPD-405');

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'open_view'), ['arguments' => []])
        ->assertJsonPath('output.saved_views', ['Mi lunes (Jira)']);

    $this->actingAs($this->user)->postJson(route('voice.tools.run', 'forget_view'), ['arguments' => ['name' => 'lunes']])->assertJsonPath('output.forgotten', 'Mi lunes');

    expect($this->user->savedViews()->count())->toBe(0);
});

test('with no composed view on screen there is nothing to save', function () {
    Cache::flush();

    $this->actingAs($this->user)
        ->postJson(route('voice.tools.run', 'save_view'), ['arguments' => ['name' => 'Mi lunes']])
        ->assertJsonPath('output.error', fn (string $error): bool => str_contains($error, 'No hay ninguna vista'));
});

test('an item from a query opens only an alert review or a day of the calendar', function () {
    $script = <<<'JS'
        const { openRef } = await import('./resources/js/canvas/openui/refs.ts');
        console.log(JSON.stringify(['alert:42', 'day:2026-10-31', 'mcp_call:x', 'alert:', undefined].map((ref) => openRef(ref))));
        JS;

    $output = shell_exec('cd '.escapeshellarg(base_path()).' && node --input-type=module -e '.escapeshellarg($script).' 2>&1');

    expect(json_decode((string) $output, true))->toBe([
        ['tool' => 'review_alert', 'args' => ['alert_id' => 42]],
        ['tool' => 'show_calendar', 'args' => ['start_date' => '2026-10-31']],
        null,
        null,
        null,
    ], (string) $output);
})->skip(fn (): bool => trim((string) shell_exec('command -v node')) === '', 'Node is not installed.');

test('a view groups its buttons, the main one first', function () {
    expect(<<<'UI'
        root = View("Siguiente paso", [next])
        next = Buttons([main, other])
        main = Button("Ver la semana", @ToAssistant("Enséñame la semana"), "primary")
        other = Button("Más tarde", null, "tertiary")
        UI)->toBeValidOpenUi();
});
