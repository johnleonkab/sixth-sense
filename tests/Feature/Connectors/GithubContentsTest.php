<?php

use App\Connections\AccountNeedsReconnection;
use App\Connections\AccountStatus;
use App\Connections\TokenRefreshFailed;
use App\Connectors\GitHub\GithubContents;
use App\Connectors\GitHub\GithubWriteNotAllowed;
use App\Models\GithubRepository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->repository = GithubRepository::factory()->writable()->create([
        'owner' => 'jhon',
        'name' => 'sixth-sense',
        'full_name' => 'jhon/sixth-sense',
        'default_branch' => 'main',
    ]);
});

test('updating a file sends the new contents and the blob being replaced', function () {
    Http::fake([
        'api.github.com/repos/jhon/sixth-sense/contents/docs/plan.md*' => Http::sequence()
            ->push(['sha' => 'abc123', 'path' => 'docs/plan.md'])
            ->push(['content' => ['path' => 'docs/plan.md', 'sha' => 'def456'], 'commit' => ['sha' => 'cafebabe']]),
    ]);

    $updated = app(GithubContents::class)->update($this->repository, 'docs/plan.md', "# Plan\n", 'Describe the plan');

    expect($updated)->toBe(['path' => 'docs/plan.md', 'sha' => 'def456', 'commit' => 'cafebabe', 'url' => '']);

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/contents/docs/plan.md')
        && $request['message'] === 'Describe the plan'
        && $request['content'] === base64_encode("# Plan\n")
        && $request['sha'] === 'abc123'
        && $request['branch'] === 'main');
});

test('a new file is created without a previous blob', function () {
    Http::fake([
        'api.github.com/repos/jhon/sixth-sense/contents/notes.md*' => Http::sequence()
            ->push([], 404)
            ->push(['content' => ['path' => 'notes.md', 'sha' => 'fff'], 'commit' => ['sha' => '111']]),
    ]);

    app(GithubContents::class)->update($this->repository, 'notes.md', 'hola', 'Add notes');

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && ! array_key_exists('sha', $request->data()));
});

test('code is not updated unless the person allowed it on that repository', function (GithubRepository $repository) {
    expect(fn () => app(GithubContents::class)->update($repository, 'README.md', 'x', 'Update'))
        ->toThrow(GithubWriteNotAllowed::class);

    Http::assertNothingSent();
})->with([
    'not included' => fn () => GithubRepository::factory()->create(['included' => false, 'writes_code' => false, 'can_push' => true]),
    'included but read only' => fn () => GithubRepository::factory()->create(['included' => true, 'writes_code' => false, 'can_push' => true]),
    'github itself refuses pushes' => fn () => GithubRepository::factory()->create(['included' => true, 'writes_code' => true, 'can_push' => false]),
]);

test('an expired github token is renewed with the refresh token, which rotates', function () {
    config(['services.github.client_id' => 'github-client', 'services.github.client_secret' => 'github-secret']);
    $account = $this->repository->account;
    $account->update(['access_token' => 'gho_old', 'refresh_token' => 'ghr_old', 'expires_at' => now()->subMinute()]);
    Http::fake(fn (Request $request) => str_contains($request->url(), 'login/oauth/access_token')
        ? Http::response(['access_token' => 'gho_new', 'refresh_token' => 'ghr_new', 'expires_in' => 28800, 'scope' => 'repo', 'token_type' => 'bearer'])
        : Http::response(['sha' => 'abc', 'content' => base64_encode("hola\n"), 'path' => 'docs/plan.md']));

    expect(app(GithubContents::class)->read($this->repository, 'docs/plan.md')['content'])->toBe("hola\n")
        ->and($account->fresh()->access_token)->toBe('gho_new')
        ->and($account->refresh_token)->toBe('ghr_new');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'access_token')
        && $request->isForm()
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'ghr_old'
        && $request['client_id'] === 'github-client');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/contents/') && ($request->header('Authorization')[0] ?? null) === 'Bearer gho_new');
});

test('a token github refuses is renewed once and the read is repeated', function () {
    config(['services.github.client_id' => 'github-client', 'services.github.client_secret' => 'github-secret']);
    $account = $this->repository->account;
    $account->update(['access_token' => 'gho_old', 'refresh_token' => 'ghr_old', 'expires_at' => now()->addHour()]);
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'login/oauth/access_token')) {
            return Http::response(['access_token' => 'gho_new', 'refresh_token' => 'ghr_new', 'expires_in' => 28800, 'scope' => 'repo']);
        }

        return ($request->header('Authorization')[0] ?? null) === 'Bearer gho_old'
            ? Http::response(['message' => 'Bad credentials'], 401)
            : Http::response(['sha' => 'abc', 'content' => base64_encode('hola'), 'path' => 'docs/plan.md']);
    });

    expect(app(GithubContents::class)->read($this->repository, 'docs/plan.md')['content'])->toBe('hola')
        ->and($account->fresh()->access_token)->toBe('gho_new');
});

test('a form-encoded refresh still stores the rotated token', function () {
    config(['services.github.client_id' => 'github-client', 'services.github.client_secret' => 'github-secret']);
    $account = $this->repository->account;
    $account->update(['access_token' => 'gho_old', 'refresh_token' => 'ghr_old', 'expires_at' => now()->subMinute()]);
    Http::fake(fn (Request $request) => str_contains($request->url(), 'login/oauth/access_token')
        ? Http::response(
            'access_token=gho_new&expires_in=28800&refresh_token=ghr_new&scope=repo&token_type=bearer',
            200,
            ['Content-Type' => 'application/x-www-form-urlencoded'],
        )
        : Http::response(['sha' => 'abc', 'content' => base64_encode("hola\n"), 'path' => 'docs/plan.md']));

    expect(app(GithubContents::class)->read($this->repository, 'docs/plan.md')['content'])->toBe("hola\n")
        ->and($account->fresh()->access_token)->toBe('gho_new')
        ->and($account->refresh_token)->toBe('ghr_new');
});

test('a form-encoded refusal asks to connect github again', function () {
    config(['services.github.client_id' => 'github-client', 'services.github.client_secret' => 'github-secret']);
    $account = $this->repository->account;
    $account->update(['refresh_token' => 'ghr_old', 'expires_at' => now()->subMinute()]);
    Http::fake([
        'github.com/login/oauth/access_token' => Http::response(
            'error=bad_refresh_token&error_description=The+refresh+token+is+invalid',
            200,
            ['Content-Type' => 'application/x-www-form-urlencoded'],
        ),
    ]);

    expect(fn () => app(GithubContents::class)->read($this->repository, 'docs/plan.md'))->toThrow(AccountNeedsReconnection::class);
    expect($account->fresh()->status)->toBe(AccountStatus::NeedsReconnection);
});

test('a refused refresh token asks to connect github again', function () {
    config(['services.github.client_id' => 'github-client', 'services.github.client_secret' => 'github-secret']);
    $account = $this->repository->account;
    $account->update(['refresh_token' => 'ghr_old', 'expires_at' => now()->subMinute()]);
    Http::fake(['github.com/login/oauth/access_token' => Http::response(['error' => 'bad_refresh_token'], 400)]);

    expect(fn () => app(GithubContents::class)->read($this->repository, 'docs/plan.md'))->toThrow(AccountNeedsReconnection::class);
    expect($account->fresh()->status)->toBe(AccountStatus::NeedsReconnection);
});

test('a github refresh that fails for a moment keeps the connection', function () {
    config(['services.github.client_id' => 'github-client', 'services.github.client_secret' => 'github-secret']);
    $account = $this->repository->account;
    $account->update(['refresh_token' => 'ghr_old', 'expires_at' => now()->subMinute()]);
    Http::fake(['github.com/login/oauth/access_token' => Http::response([], 500)]);

    expect(fn () => app(GithubContents::class)->read($this->repository, 'docs/plan.md'))->toThrow(TokenRefreshFailed::class);
    expect($account->fresh()->status)->toBe(AccountStatus::Active);
});
