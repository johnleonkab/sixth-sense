<?php

use App\Connections\Provider;
use App\Models\ConnectedAccount;
use App\Models\GithubRepository;
use App\Models\SenseAgent;
use App\Models\User;
use App\Sense\DocumentRules;
use App\Sense\Scaffold;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('the scaffold obeys its own type rules', function () {
    $files = Scaffold::files();

    foreach ($files as $path => $contents) {
        $document = DocumentRules::document($contents);
        $type = is_array($document) ? ($document['type'] ?? null) : null;
        $spec = is_string($type) ? DocumentRules::parse($files["types/{$type}.yml"] ?? '') : null;

        expect(DocumentRules::violation($path, $contents, $spec))->toBeNull("{$path} broke its rules");
    }

    expect($files['CLAUDE.md'])->toStartWith('# Cómo se trabaja');
});

test('a repository without the scaffold is not adopted', function () {
    $repository = GithubRepository::factory()->writable()->create([
        'owner' => 'jhon',
        'name' => 'notes',
        'full_name' => 'jhon/notes',
    ]);

    Http::fake([
        'api.github.com/*' => Http::response(['tree' => [['path' => 'README.md', 'type' => 'blob']]]),
    ]);

    $this->actingAs($repository->account->user)
        ->post(route('settings.connections.sense.adopt', [$repository->account, $repository]))
        ->assertRedirect()
        ->assertSessionHasErrors('workspace');

    expect($repository->fresh()->is_work)->toBeFalse()
        ->and(session('errors')->get('workspace')[0])->toContain('Falta CLAUDE.md');
});

test('a complete repository becomes the only work archive and its keeper is copied', function () {
    $account = githubAccount();
    $previous = GithubRepository::factory()->writable()->create([
        'connected_account_id' => $account->id,
        'is_work' => true,
        'full_name' => 'jhon/old',
    ]);
    $repository = GithubRepository::factory()->writable()->create([
        'connected_account_id' => $account->id,
        'owner' => 'jhon',
        'name' => 'sense',
        'full_name' => 'jhon/sense',
        'is_work' => false,
    ]);

    Http::fake(fn (Request $request) => scaffoldResponse($request));

    $this->actingAs($account->user)
        ->post(route('settings.connections.sense.adopt', [$account, $repository]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($repository->fresh()->is_work)->toBeTrue()
        ->and($previous->fresh()->is_work)->toBeFalse()
        ->and(SenseAgent::query()->where('user_id', $account->user_id)->pluck('name', 'slug')->all())
        ->toBe(['keeper' => 'keeper']);
});

test('creating the archive opens a private repository and commits the scaffold', function () {
    $account = githubAccount();
    $puts = 0;

    Http::fake(function (Request $request) use (&$puts) {
        if ($request->method() === 'POST' && str_contains($request->url(), '/user/repos')) {
            return Http::response([
                'id' => 42,
                'name' => 'sense',
                'full_name' => 'jhon/sense',
                'private' => true,
                'default_branch' => 'main',
                'owner' => ['login' => 'jhon'],
            ]);
        }

        return scaffoldResponse($request, $puts);
    });

    $this->actingAs($account->user)
        ->post(route('settings.connections.sense.store', $account))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $repository = $account->githubRepositories()->first();

    expect($repository->is_work)->toBeTrue()
        ->and($repository->is_private)->toBeTrue()
        ->and($repository->full_name)->toBe('jhon/sense')
        ->and($puts)->toBe(count(Scaffold::paths()))
        ->and($account->user->senseAgents()->where('slug', 'keeper')->exists())->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/user/repos')
        && $request['name'] === 'sense'
        && $request['private'] === true);
});

test('someone else cannot adopt the repository', function () {
    $repository = GithubRepository::factory()->writable()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('settings.connections.sense.adopt', [$repository->account, $repository]))
        ->assertNotFound();
});

function githubAccount(): ConnectedAccount
{
    return ConnectedAccount::factory()->create([
        'provider' => Provider::GitHub,
        'scopes' => Provider::GitHub->scopes(),
        'refresh_token' => null,
        'expires_at' => null,
        'metadata' => ['login' => 'jhon'],
    ]);
}

/**
 * GitHub answers as if the scaffold were already there. While `$puts` is
 * counting a create, the repository stays empty until every file is put.
 */
function scaffoldResponse(Request $request, ?int &$puts = null): PromiseInterface
{
    $writing = $puts !== null && $puts < count(Scaffold::paths());
    $url = $request->url();

    if (str_contains($url, '/git/trees/')) {
        if ($writing) {
            return Http::response(['message' => 'Git Repository is empty.'], 409);
        }

        return Http::response([
            'tree' => array_map(fn (string $path): array => ['path' => $path, 'type' => 'blob'], Scaffold::paths()),
        ]);
    }

    if ($request->method() === 'PUT') {
        $puts++;

        return Http::response(['content' => ['path' => 'x', 'sha' => 's'], 'commit' => ['sha' => 'c']]);
    }

    $body = scaffoldBody($url);

    if ($request->method() === 'GET' && str_contains($url, '/contents/')) {
        if ($body === null || $writing) {
            return Http::response([], 404);
        }

        return Http::response(['content' => base64_encode($body), 'sha' => 'abc', 'encoding' => 'base64']);
    }

    return Http::response('unexpected', 500);
}

function scaffoldBody(string $url): ?string
{
    $files = Scaffold::files();
    uksort($files, fn (string $left, string $right): int => strlen($right) <=> strlen($left));

    foreach ($files as $path => $contents) {
        $encoded = implode('/', array_map(rawurlencode(...), explode('/', $path)));

        if (str_contains($url, "/contents/{$encoded}")) {
            return $contents;
        }
    }

    return null;
}
