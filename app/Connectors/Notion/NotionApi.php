<?php

namespace App\Connectors\Notion;

use App\Connections\AccessTokens;
use App\Connections\AccountNeedsReconnection;
use App\Connections\AccountStatus;
use App\Connections\TokenRefreshFailed;
use App\Models\ConnectedAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Calls the Notion API as the person. A refused token is renewed once; a
 * rate limit is waited out once, if short.
 *
 * @see https://developers.notion.com/reference/intro
 */
class NotionApi
{
    public const VERSION = '2026-03-11';

    private const BASE = 'https://api.notion.com/v1/';

    public function __construct(private AccessTokens $tokens) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(ConnectedAccount $account, string $path, array $query = []): array
    {
        return $this->call($account, 'get', $path, $query);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(ConnectedAccount $account, string $path, array $body = []): array
    {
        return $this->call($account, 'post', $path, $body);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function patch(ConnectedAccount $account, string $path, array $body = []): array
    {
        return $this->call($account, 'patch', $path, $body);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function call(ConnectedAccount $account, string $verb, string $path, array $data): array
    {
        $token = $this->tokens->for($account);
        $renewed = false;

        for ($attempt = 1; ; $attempt++) {
            $response = $this->send($token, $verb, $path, $data);

            if ($response->status() === 401 && ! $renewed) {
                $renewed = true;

                try {
                    $token = $this->tokens->renew($account, $token);
                } catch (TokenRefreshFailed) {
                    break;
                }

                continue;
            }

            if ($response->status() === 429 && $attempt === 1 && (int) $response->header('Retry-After') <= 5) {
                Sleep::for(max(1, (int) $response->header('Retry-After')))->seconds();

                continue;
            }

            break;
        }

        if ($response->status() === 401) {
            $account->update(['status' => AccountStatus::NeedsReconnection]);

            throw new AccountNeedsReconnection($account);
        }

        if ($response->failed()) {
            throw new NotionError($response->json('code') ?? "http_{$response->status()}", (string) ($response->json('message') ?? "Notion {$path} failed with HTTP {$response->status()}"), $response->status());
        }

        return $response->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(string $token, string $verb, string $path, array $data): Response
    {
        $request = Http::withToken($token)->withHeaders(['Notion-Version' => self::VERSION])->acceptJson()->timeout(20);

        return match ($verb) {
            'get' => $request->get(self::BASE.$path, $data),
            'post' => $request->post(self::BASE.$path, $data),
            'patch' => $request->patch(self::BASE.$path, $data),
            default => throw new RuntimeException("Unsupported verb [{$verb}]."),
        };
    }
}
