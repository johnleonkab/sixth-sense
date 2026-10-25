<?php

namespace App\Connectors\GitHub;

use App\Connections\AccessTokens;
use App\Connections\AccountNeedsReconnection;
use App\Connections\AccountStatus;
use App\Models\ConnectedAccount;
use App\Models\GithubRepository;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Calls the GitHub REST API as the connected person.
 *
 * @see https://docs.github.com/en/rest
 */
class GithubApi
{
    private const API = 'https://api.github.com';

    public function __construct(private AccessTokens $tokens) {}

    /**
     * Repositories the account can access, across every page GitHub returns.
     *
     * @return list<array<string, mixed>>
     */
    public function repositories(ConnectedAccount $account): array
    {
        $all = [];

        for ($page = 1; $page <= 10; $page++) {
            $batch = $this->get($account, '/user/repos', [
                'per_page' => 100,
                'page' => $page,
                'sort' => 'full_name',
                'affiliation' => 'owner,collaborator,organization_member',
            ]);

            if (! array_is_list($batch)) {
                throw new RuntimeException('GitHub returned an unexpected repository list.');
            }

            $all = [...$all, ...$batch];

            if (count($batch) < 100) {
                break;
            }
        }

        return $all;
    }

    /**
     * The file at this path, or null when the repository does not have it.
     *
     * @return array<string, mixed>|null
     */
    public function file(GithubRepository $repository, string $path, string $branch): ?array
    {
        $response = $this->send($repository->account, 'get', $this->contentsPath($repository, $path), ['ref' => $branch]);

        if ($response->status() === 404) {
            return null;
        }

        return $this->decoded($response);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function putContents(GithubRepository $repository, string $path, array $body): array
    {
        return $this->decoded($this->send($repository->account, 'put', $this->contentsPath($repository, $path), $body));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function deleteContents(GithubRepository $repository, string $path, array $body): array
    {
        return $this->decoded($this->send($repository->account, 'delete', $this->contentsPath($repository, $path), $body));
    }

    /**
     * Create a private repository on the account. The name has to be free.
     *
     * @return array<string, mixed>
     */
    public function createRepository(ConnectedAccount $account, string $name, string $description): array
    {
        return $this->decoded($this->send($account, 'post', '/user/repos', [
            'name' => $name,
            'description' => $description,
            'private' => true,
            'auto_init' => false,
        ]));
    }

    /**
     * Every file path in the branch. An empty repository has none.
     * The branch defaults to the repository's default branch.
     *
     * @return list<string>
     */
    public function paths(GithubRepository $repository, ?string $branch = null): array
    {
        $response = $this->send(
            $repository->account,
            'get',
            '/repos/'.rawurlencode($repository->owner).'/'.rawurlencode($repository->name).'/git/trees/'.rawurlencode($branch ?? $repository->default_branch),
            ['recursive' => 1],
        );

        if (in_array($response->status(), [404, 409], true)) {
            return [];
        }

        $tree = $this->decoded($response)['tree'] ?? [];

        if (! is_array($tree)) {
            return [];
        }

        $paths = [];

        foreach ($tree as $item) {
            if (is_array($item) && ($item['type'] ?? null) === 'blob' && is_string($item['path'] ?? null)) {
                $paths[] = $item['path'];
            }
        }

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private function get(ConnectedAccount $account, string $path, array $query = []): array
    {
        return $this->decoded($this->send($account, 'get', $path, $query));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(ConnectedAccount $account, string $verb, string $path, array $data): Response
    {
        $token = $this->tokens->for($account);
        $response = $this->request($token, $verb, $path, $data);

        if ($response->status() !== 401) {
            return $response;
        }

        $token = $this->tokens->renew($account, $token);
        $response = $this->request($token, $verb, $path, $data);

        if ($response->status() === 401) {
            $account->update(['status' => AccountStatus::NeedsReconnection]);

            throw new AccountNeedsReconnection($account);
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function request(string $token, string $verb, string $path, array $data): Response
    {
        $pending = Http::withToken($token)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'sixth-sense',
                'X-GitHub-Api-Version' => '2026-03-10',
            ])
            ->timeout(20)
            ->baseUrl(self::API);

        return $verb === 'get'
            ? $pending->get($path, $data)
            : $pending->send($verb, $path, ['json' => $data]);
    }

    /**
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private function decoded(Response $response): array
    {
        if ($response->failed()) {
            throw new RuntimeException("GitHub failed with HTTP {$response->status()}.");
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new RuntimeException('GitHub returned an unexpected response.');
        }

        return $json;
    }

    private function contentsPath(GithubRepository $repository, string $path): string
    {
        $encoded = implode('/', array_map(rawurlencode(...), explode('/', ltrim($path, '/'))));

        return '/repos/'.rawurlencode($repository->owner).'/'.rawurlencode($repository->name)."/contents/{$encoded}";
    }
}
