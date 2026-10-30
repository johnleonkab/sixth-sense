<?php

use App\Connectors\Issues\FakeIssueTracker;
use App\Connectors\Issues\IssueTracker;
use App\Models\ConnectedAccount;
use App\Models\InboxItem;
use App\Models\User;
use App\Voice\OpenUi\Data\Datasets;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->app->instance(IssueTracker::class, new FakeIssueTracker);
});

function queryDataset(string $dataset, array $arguments = []): TestResponse
{
    return test()->actingAs(test()->user)->postJson(route('canvas.query', $dataset), ['arguments' => $arguments, 'timezone' => 'Europe/Madrid']);
}

test('a view gets Jira issues as rows it can group', function () {
    queryDataset('jira_issues', ['text' => 'pago'])
        ->assertOk()
        ->assertJsonPath('fields.status', 'text')
        ->assertJsonPath('fields.updated', 'date')
        ->assertJsonPath('rows.0.key', 'CPD-405')
        ->assertJsonStructure(['rows' => [['key', 'summary', 'type', 'status', 'status_category', 'priority', 'assignee', 'updated']]]);
});

test('a view gets only the signed-in person\'s alerts', function () {
    InboxItem::factory()->for($this->user)->create(['actor_name' => 'Marta Ruiz']);
    InboxItem::factory()->create(['actor_name' => 'De otra persona']);

    queryDataset('alerts')
        ->assertOk()
        ->assertJsonCount(1, 'rows')
        ->assertJsonPath('rows.0.actor', 'Marta Ruiz')
        ->assertJsonPath('rows.0.source', 'Gmail');
});

test('a view gets an Analytics report as rows with one field per dimension and metric', function () {
    ConnectedAccount::factory()->for($this->user)->create();
    Http::fake([
        'analyticsadmin.googleapis.com/*' => Http::response(['accountSummaries' => [['displayName' => 'CM', 'propertySummaries' => [['property' => 'properties/1', 'displayName' => 'Web']]]]]),
        'analyticsdata.googleapis.com/*' => Http::response(['rows' => [
            ['dimensionValues' => [['value' => 'Organic Search']], 'metricValues' => [['value' => '620']]],
            ['dimensionValues' => [['value' => 'Direct']], 'metricValues' => [['value' => '380']]],
        ]]),
    ]);

    queryDataset('analytics_rows', ['property' => 'web', 'metrics' => ['sessions'], 'dimensions' => ['sessionDefaultChannelGroup']])
        ->assertOk()
        ->assertJsonPath('rows', [
            ['sessionDefaultChannelGroup' => 'Organic Search', 'sessions' => 620],
            ['sessionDefaultChannelGroup' => 'Direct', 'sessions' => 380],
        ]);
});

test('only listed datasets with valid arguments can be queried, and only signed in', function () {
    queryDataset('propose_slack_message')->assertNotFound();
    queryDataset('jira_issues', ['limit' => 1000])->assertUnprocessable()->assertJsonStructure(['error']);
    queryDataset('analytics_rows', ['property' => 'nada', 'metrics' => ['sessions']])->assertUnprocessable();

    auth()->logout();
    $this->postJson(route('canvas.query', 'alerts'))->assertUnauthorized();
});

test('the datasets the prompt describes are the ones Laravel serves', function () {
    $exported = json_decode((string) file_get_contents(resource_path('openui/datasets.json')), true);

    expect($exported)->toBe(json_decode((string) json_encode(app(Datasets::class)->specs()), true));
});

test('a view that queries a dataset and charts it with the grouping functions is valid', function () {
    expect(<<<'UI'
        root = View("Experiences por estado", [donut, trend])
        issues = Query("jira_issues", {jql: "project = EXP"}, {rows: []})
        byStatus = @Top(@GroupCount(issues.rows, "status"), 6)
        donut = DonutChart("Por estado", byStatus.labels, byStatus.values)
        perDay = @ByDay(issues.rows, "updated")
        trend = LineChart("Actualizados por día", perDay.labels, perDay.values)
        UI)->toBeValidOpenUi();
});

test('the grouping functions count, add up, fold into Otros and order days', function () {
    $script = <<<'JS'
        const { groupCount, groupSum, top, byDay } = await import('./resources/js/canvas/openui/spec.ts');
        const rows = [
            { status: 'Done', points: 3, updated: '2026-10-02' },
            { status: 'To Do', points: 5, updated: '2026-10-01' },
            { status: 'Done', points: 2, updated: '2026-10-02' },
            { status: 'Blocked', points: 1, updated: '2026-10-03' },
            { status: null, points: 4, updated: '2026-10-01' },
        ];
        console.log(JSON.stringify({
            count: groupCount(rows, 'status'),
            sum: groupSum(rows, 'status', 'points'),
            top: top(groupCount(rows, 'status'), 3),
            days: byDay(rows, 'updated'),
        }));
        JS;

    $output = shell_exec('cd '.escapeshellarg(base_path()).' && node --input-type=module -e '.escapeshellarg($script).' 2>&1');
    $result = json_decode((string) $output, true);

    expect($result)->toBe([
        'count' => ['labels' => ['Done', 'To Do', 'Blocked', '(sin valor)'], 'values' => [2, 1, 1, 1]],
        'sum' => ['labels' => ['Done', 'To Do', '(sin valor)', 'Blocked'], 'values' => [5, 5, 4, 1]],
        'top' => ['labels' => ['Done', 'To Do', 'Otros'], 'values' => [2, 1, 2]],
        'days' => ['labels' => ['2026-10-01', '2026-10-02', '2026-10-03'], 'values' => [2, 2, 1]],
    ], (string) $output);
})->skip(fn (): bool => trim((string) shell_exec('command -v node')) === '', 'Node is not installed.');
