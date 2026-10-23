<?php

use App\Models\GithubRepository;
use App\Sense\PublishContext;
use App\WorkContext\WorkDigest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('the nightly context updates the archive and its agents', function () {
    Http::preventStrayRequests();
    $repository = GithubRepository::factory()->writable()->create([
        'owner' => 'jhon',
        'name' => 'sense',
        'full_name' => 'jhon/sense',
        'is_work' => true,
    ]);
    $stored = [];

    Http::fake(function (Request $request) use (&$stored) {
        $url = $request->url();

        if (str_contains($url, '/git/trees/')) {
            return Http::response([
                'tree' => array_map(fn (string $path): array => ['path' => $path, 'type' => 'blob'], array_keys($stored)),
            ]);
        }

        $path = archivePath($url);

        if ($request->method() === 'PUT' && $path !== null) {
            $stored[$path] = base64_decode((string) $request['content']);

            return Http::response(['content' => ['path' => $path, 'sha' => 's'], 'commit' => ['sha' => 'c']]);
        }

        if ($request->method() === 'GET' && isset($stored[$path])) {
            return Http::response(['content' => base64_encode($stored[$path]), 'sha' => 's']);
        }

        return Http::response([], 404);
    });

    app(PublishContext::class)->publish($repository->account->user, new WorkDigest(
        summary: 'Cerrando el prepago.',
        writingSlack: 'Corto y directo.',
        writingGmail: 'Empieza por el motivo.',
        contacts: [['name' => 'Marta Ruiz', 'language' => 'español', 'where' => 'Slack', 'note' => 'Lleva el prepago']],
    ));

    expect(array_keys($stored))->toEqualCanonicalizing([
        'now.md',
        'agents/gmail-writer/agent.md',
        'agents/slack-writer/agent.md',
        'agents/review/agent.md',
    ])
        ->and($stored['now.md'])->toContain('Cerrando el prepago.')
        ->and($stored['agents/slack-writer/agent.md'])->toContain('Corto y directo.')
        ->and($stored['agents/slack-writer/agent.md'])->toStartWith("---\n")
        ->and($repository->account->user->senseAgents()->orderBy('slug')->pluck('name', 'slug')->all())
        ->toBe(['gmail-writer' => 'Gmail', 'review' => 'Revisión', 'slack-writer' => 'Slack']);
});

function archivePath(string $url): ?string
{
    foreach (['now.md', 'agents/gmail-writer/agent.md', 'agents/slack-writer/agent.md', 'agents/review/agent.md'] as $path) {
        $encoded = implode('/', array_map(rawurlencode(...), explode('/', $path)));

        if (str_contains($url, "/contents/{$encoded}")) {
            return $path;
        }
    }

    return null;
}
