<?php

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
