<?php

use App\Connections\Capability;
use App\Connections\Provider;
use App\Connectors\Jira\JiraRules;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'app.url' => 'http://localhost:8000',
        'services.atlassian.client_id' => 'atl-client',
        'services.atlassian.client_secret' => 'atl-secret',
        'services.atlassian.redirect' => 'http://localhost:8000/settings/connections/atlassian/callback',
    ]);
    $this->user = User::factory()->create();
});

test('users are sent to atlassian with the jira scopes, a refresh token and consent', function () {
    $location = $this->actingAs($this->user)->get(route('settings.connections.redirect', 'atlassian'))->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://auth.atlassian.com/authorize')
        ->and($query)->toMatchArray(['audience' => 'api.atlassian.com', 'client_id' => 'atl-client', 'response_type' => 'code', 'prompt' => 'consent'])
        ->and(explode(' ', $query['scope']))->toContain('read:jira-work', 'write:jira-work', 'read:jira-user', 'read:me', 'offline_access');
});

test('the callback stores the jira site with every capability', function () {
    $location = $this->actingAs($this->user)->get(route('settings.connections.redirect', 'atlassian'))->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    Http::fake([
        'auth.atlassian.com/oauth/token' => Http::response(['access_token' => 'tok', 'refresh_token' => 'ref', 'expires_in' => 3600, 'scope' => 'read:jira-work write:jira-work read:jira-user read:me offline_access']),
        'api.atlassian.com/me' => Http::response(['account_id' => 'u-me', 'email' => 'jhon@cm.com', 'name' => 'John León']),
        'api.atlassian.com/oauth/token/accessible-resources' => Http::response([
            ['id' => 'conf-1', 'name' => 'wiki', 'url' => 'https://wiki.atlassian.net', 'scopes' => ['read:confluence-content.all']],
            ['id' => 'cloud-1', 'name' => 'covermanager', 'url' => 'https://covermanager.atlassian.net', 'scopes' => ['read:jira-work', 'write:jira-work']],
        ]),
    ]);

    $this->get(route('settings.connections.callback', ['provider' => 'atlassian', 'code' => 'abc', 'state' => $query['state']]))->assertRedirect();

    $account = ConnectedAccount::sole();

    expect($account->provider)->toBe(Provider::Atlassian)
        ->and($account->external_account_id)->toBe('cloud-1:u-me')
        ->and($account->metadata)->toMatchArray(['cloud_id' => 'cloud-1', 'site_name' => 'covermanager', 'account_id' => 'u-me'])
        ->and($account->can(Capability::JiraWrite))->toBeTrue()
        ->and($account->can(Capability::JiraComment))->toBeTrue();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'oauth/token') && ($request->data()['grant_type'] ?? null) === 'authorization_code' && ($request->data()['client_secret'] ?? null) === 'atl-secret');
});

test('each jira connection has its rules, starting from the template, which the person edits', function () {
    $account = ConnectedAccount::factory()->for($this->user)->create(['provider' => Provider::Atlassian, 'scopes' => Provider::Atlassian->scopes(), 'metadata' => ['cloud_id' => 'c', 'site_name' => 'covermanager']]);

    $this->actingAs($this->user)->get(route('settings.connections.rules.edit', $account->id))
        ->assertInertia(fn ($page) => $page->component('settings/jira-rules')
            ->where('rules', JiraRules::template())
            ->where('saved', false)
            ->where('understood.projects', ['CPD']));

    $this->actingAs($this->user)->put(route('settings.connections.rules.update', $account->id), ['rules' => "## Proyectos y filtros\n- Proyectos: OPS"])->assertRedirect();

    expect($account->refresh()->rules)->toBe("## Proyectos y filtros\n- Proyectos: OPS");

    $this->actingAs($this->user)->get(route('settings.connections.rules.edit', $account->id))->assertInertia(fn ($page) => $page->where('understood.projects', ['OPS'])->where('saved', true));
});

test('rules can only be edited on the person own jira connections', function () {
    $other = ConnectedAccount::factory()->create(['provider' => Provider::Atlassian]);
    $google = ConnectedAccount::factory()->for($this->user)->create(['provider' => Provider::Google]);

    $this->actingAs($this->user)->get(route('settings.connections.rules.edit', $other->id))->assertNotFound();
    $this->actingAs($this->user)->get(route('settings.connections.rules.edit', $google->id))->assertNotFound();
});
