<?php

use App\Connections\Provider;
use App\Connectors\Issues\IssueChanges;
use App\Connectors\Issues\NewIssue;
use App\Connectors\Jira\JiraIssueTracker;
use App\Models\ConnectedAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const JIRA = 'https://api.atlassian.com/ex/jira/cloud-1/rest/api/3/';

function jiraIssue(string $key, array $fields = []): array
{
    return ['key' => $key, 'fields' => [
        'summary' => "Issue {$key}", 'issuetype' => ['name' => 'Story'], 'status' => ['name' => 'To Do', 'statusCategory' => ['key' => 'new']],
        'priority' => ['name' => 'Medium'], 'assignee' => ['displayName' => 'John León'], 'updated' => '2026-10-13T09:00:00.000+0200',
        ...$fields,
    ]];
}

function jiraComment(string $id, string $author, string $text, string $created, array $mentions = []): array
{
    return ['id' => $id, 'author' => ['accountId' => $author, 'displayName' => $author === 'u-me' ? 'John León' : 'Marta Ruiz'], 'created' => $created, 'body' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
        ...array_map(fn (string $id) => ['type' => 'mention', 'attrs' => ['id' => $id, 'text' => '@John León']], $mentions),
        ['type' => 'text', 'text' => $text],
    ]]]]];
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->account = ConnectedAccount::factory()->for($this->user)->create([
        'provider' => Provider::Atlassian,
        'external_account_id' => 'cloud-1:u-me',
        'email' => 'jhon@cm.com',
        'access_token' => 'tok',
        'refresh_token' => 'ref-1',
        'expires_at' => now()->addHour(),
        'scopes' => Provider::Atlassian->scopes(),
        'metadata' => ['cloud_id' => 'cloud-1', 'site_name' => 'covermanager', 'site_url' => 'https://covermanager.atlassian.net', 'account_id' => 'u-me'],
        'rules' => "## Proyectos y filtros\n- Proyectos: CPD\n- JQL base: project = CPD ORDER BY updated DESC\n## Dependencias\n- Tipo de enlace: Blocks",
    ]);
    $this->jira = app(JiraIssueTracker::class);
    $this->a = $this->account->id;
});

test('search runs the jql of the rules, or text within their projects', function () {
    Http::fake([JIRA.'search/jql' => Http::response(['issues' => [jiraIssue('CPD-1')]])]);

    $found = $this->jira->search($this->user, null);
    $this->jira->search($this->user, null, 'prepago "v4"');

    expect($found[0]->ref)->toBe("{$this->a}:CPD-1")
        ->and($found[0]->url)->toBe('https://covermanager.atlassian.net/browse/CPD-1')
        ->and($found[0]->site)->toBe('covermanager');

    Http::assertSent(fn (Request $request) => $request['jql'] === 'project = CPD ORDER BY updated DESC');
    Http::assertSent(fn (Request $request) => $request['jql'] === 'text ~ "prepago \"v4\"" AND project in (CPD) ORDER BY updated DESC');
});

test('an issue opens by key, with its dependencies and its comments, those mentioning the user marked', function () {
    Http::fake([
        JIRA.'issue/CPD-1/comment*' => Http::response(['comments' => [jiraComment('10', 'u-marta', ' ¿lo revisas?', '2026-10-13T08:00:00.000+0000', ['u-me'])]]),
        JIRA.'issue/CPD-1*' => Http::response(jiraIssue('CPD-1', [
            'description' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Mostrar el total']]]]],
            'parent' => ['key' => 'CPD-0', 'fields' => ['summary' => 'Prepago', 'issuetype' => ['name' => 'Epic']]],
            'issuelinks' => [['id' => '77', 'type' => ['name' => 'Blocks', 'inward' => 'is blocked by', 'outward' => 'blocks'], 'inwardIssue' => jiraIssue('CPD-2')]],
        ])),
    ]);

    $issue = $this->jira->issue($this->user, 'https://covermanager.atlassian.net/browse/CPD-1');

    expect($issue->summary->ref)->toBe("{$this->a}:CPD-1")
        ->and($issue->descriptionText)->toBe('Mostrar el total')
        ->and($issue->parent)->toBe(['key' => 'CPD-0', 'summary' => 'Prepago', 'type' => 'Epic'])
        ->and($issue->links[0])->toMatchArray(['ref' => "{$this->a}:77", 'relation' => 'is blocked by', 'key' => 'CPD-2'])
        ->and($issue->comments[0]->ref)->toBe("{$this->a}:CPD-1:10")
        ->and($issue->comments[0]->mentionsMe)->toBeTrue()
        ->and($issue->comments[0]->mine)->toBeFalse();
});

test('issues are created with their parent, priority and a rich description', function () {
    Http::fake([
        JIRA.'issue' => Http::response(['id' => '1', 'key' => 'CPD-9']),
        JIRA.'issue/CPD-9*' => Http::response(jiraIssue('CPD-9')),
    ]);

    $created = $this->jira->create($this->user, $this->a, new NewIssue('CPD', 'Story', 'As a diner…', "## Context\nTexto", parentKey: 'CPD-0', priority: 'High', labels: ['prepago']));

    expect($created->key)->toBe('CPD-9');
    Http::assertSent(fn (Request $request) => $request->url() === JIRA.'issue'
        && $request['fields']['project'] === ['key' => 'CPD']
        && $request['fields']['issuetype'] === ['name' => 'Story']
        && $request['fields']['parent'] === ['key' => 'CPD-0']
        && $request['fields']['priority'] === ['name' => 'High']
        && $request['fields']['labels'] === ['prepago']
        && $request['fields']['description']['content'][0]['type'] === 'heading');
});

test('changes, dependencies and comments go to jira as the user', function () {
    Http::fake(['*' => Http::response(['id' => '501'])]);

    $this->jira->update($this->user, "{$this->a}:CPD-1", new IssueChanges(priority: 'Highest', labels: []));
    $this->jira->link($this->user, "{$this->a}:CPD-2", "{$this->a}:CPD-1");
    $this->jira->unlink($this->user, "{$this->a}:77");
    $id = $this->jira->comment($this->user, "{$this->a}:CPD-1", '@Marta Ruiz hecho', ['Marta Ruiz' => 'u-marta']);

    expect($id)->toBe('501');
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request->url() === JIRA.'issue/CPD-1' && $request['fields'] === ['priority' => ['name' => 'Highest'], 'labels' => []]);
    // "CPD-2 blocks CPD-1": Jira gives the outward description to inwardIssue.
    Http::assertSent(fn (Request $request) => $request->url() === JIRA.'issueLink' && $request['inwardIssue'] === ['key' => 'CPD-2'] && $request['outwardIssue'] === ['key' => 'CPD-1'] && $request['type'] === ['name' => 'Blocks']);
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === JIRA.'issueLink/77');
    Http::assertSent(fn (Request $request) => $request->url() === JIRA.'issue/CPD-1/comment' && $request['body']['content'][0]['content'][0] === ['type' => 'mention', 'attrs' => ['id' => 'u-marta', 'text' => '@Marta Ruiz']]);
});

test('a mention counts as answered once the user commented after it', function (array $comments, bool $answered) {
    Http::fake([JIRA.'issue/CPD-1/comment*' => Http::response(['comments' => $comments])]);

    expect($this->jira->repliedAfter($this->user, "{$this->a}:CPD-1:10"))->toBe($answered);
})->with([
    'answered' => [[jiraComment('10', 'u-marta', 'x', '2026-10-13T08:00:00.000+0000'), jiraComment('11', 'u-me', 'y', '2026-10-13T08:30:00.000+0000')], true],
    'not yet' => [[jiraComment('9', 'u-me', 'y', '2026-10-13T07:00:00.000+0000'), jiraComment('10', 'u-marta', 'x', '2026-10-13T08:00:00.000+0000')], false],
]);

test('new comments mentioning the user, by others, in the projects of the rules', function () {
    Http::fake([
        JIRA.'search/jql' => Http::response(['issues' => [jiraIssue('CPD-1')]]),
        JIRA.'issue/CPD-1/comment*' => Http::response(['comments' => [
            jiraComment('12', 'u-marta', ' ¿me ayudas?', '2026-10-13T09:50:00.000+0000', ['u-me']),
            jiraComment('11', 'u-marta', ' sin mención', '2026-10-13T09:45:00.000+0000'),
            jiraComment('10', 'u-marta', ' antigua', '2026-10-13T08:00:00.000+0000', ['u-me']),
        ]]),
    ]);

    $found = $this->jira->mentionsSince($this->account, CarbonImmutable::parse('2026-10-13 09:30', 'UTC'));

    expect($found)->toHaveCount(1)
        ->and($found[0]['comment']->ref)->toBe("{$this->a}:CPD-1:12")
        ->and($found[0]['issue']->key)->toBe('CPD-1');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'search/jql') && $request['jql'] === 'updated >= "2026-10-13 09:28" AND project in (CPD) ORDER BY updated DESC');
});

test('an expired access token is refreshed, and the new refresh token kept', function () {
    $this->account->update(['expires_at' => now()->subMinute()]);
    Http::fake([
        'auth.atlassian.com/oauth/token' => Http::response(['access_token' => 'tok-2', 'refresh_token' => 'ref-2', 'expires_in' => 3600, 'scope' => 'read:jira-work']),
        JIRA.'search/jql' => Http::response(['issues' => []]),
    ]);

    $this->jira->search($this->user, null);

    expect($this->account->refresh()->access_token)->toBe('tok-2')
        ->and($this->account->refresh_token)->toBe('ref-2');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'search/jql') && $request->header('Authorization')[0] === 'Bearer tok-2');
});

test('issues are created with their components, and the project\'s components are listed', function () {
    Http::fake([
        JIRA.'project/CPD/components' => Http::response([['id' => '1', 'name' => 'coverexperiences'], ['id' => '2', 'name' => 'Pagos']]),
        JIRA.'issue' => Http::response(['id' => '1', 'key' => 'CPD-9']),
        JIRA.'issue/CPD-9*' => Http::response(jiraIssue('CPD-9')),
    ]);

    expect($this->jira->components($this->user, $this->a, 'CPD'))->toBe(['coverexperiences', 'Pagos']);

    $this->jira->create($this->user, $this->a, new NewIssue('CPD', 'Task', 'Keys de seats.io', components: ['coverexperiences']));

    Http::assertSent(fn (Request $request) => $request->url() === JIRA.'issue' && $request['fields']['components'] === [['name' => 'coverexperiences']]);
});
