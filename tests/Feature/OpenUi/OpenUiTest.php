<?php

use App\Actions\ActionStatus;
use App\Models\ConnectedAccount;
use App\Models\McpServer;
use App\Models\PendingAction;
use App\Models\User;
use App\Voice\AssistantProfile;
use App\Voice\OpenUi\OpenUi;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

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
        ->assertJsonPath('output.choose_property', ['Web (CoverManager)', 'App (CoverManager)'])
        ->assertJsonPath('canvas', null);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'runReport'));
});
