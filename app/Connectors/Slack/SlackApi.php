<?php

namespace App\Connectors\Slack;

use App\Connections\AccessTokens;
use App\Connections\AccountNeedsReconnection;
use App\Connections\AccountStatus;
use App\Models\ConnectedAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Calls the Slack Web API as the user. Slack answers HTTP 200 with
 * {"ok": false} on errors; a revoked token marks the account for reconnection.
 *
 * @see https://docs.slack.dev/reference/methods
 */
class SlackApi
{
    private const BASE = 'https://slack.com/api/';

    private const AUTH_ERRORS = ['invalid_auth', 'token_revoked', 'account_inactive', 'not_authed', 'token_expired'];

    public function __construct(private AccessTokens $tokens) {}

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function get(ConnectedAccount $account, string $method, array $parameters = []): array
    {
        return $this->call($account, 'get', $method, $parameters);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function post(ConnectedAccount $account, string $method, array $parameters = []): array
    {
        return $this->call($account, 'post', $method, $parameters);
    }

    /**
     * Bytes of a private file URL, which needs the user's token.
     */
    public function download(ConnectedAccount $account, string $url): string
    {
        $response = Http::withToken($this->tokens->for($account))->timeout(60)->get($url);

        if ($response->failed()) {
            throw new RuntimeException("Slack file download failed with HTTP {$response->status()}.");
        }

        return $response->body();
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function call(ConnectedAccount $account, string $verb, string $method, array $parameters): array
    {
        $request = Http::withToken($this->tokens->for($account))->acceptJson()->timeout(20);
        $parameters = array_filter($parameters, fn (mixed $value): bool => $value !== null);

        for ($attempt = 1; ; $attempt++) {
            $response = $verb === 'get'
                ? $request->get(self::BASE.$method, $parameters)
                : $request->asForm()->post(self::BASE.$method, $parameters);

            // Rate limited: wait as Slack asks, once, if it is short.
            if ($response->status() === 429 && $attempt === 1 && (int) $response->header('Retry-After') <= 5) {
                Sleep::for(max(1, (int) $response->header('Retry-After')))->seconds();

                continue;
            }

            break;
        }

        $data = $response->json() ?? [];

        if (($data['ok'] ?? false) === true) {
            return $data;
        }

        $error = $data['error'] ?? "http_{$response->status()}";

        if (in_array($error, self::AUTH_ERRORS, true)) {
            $account->update(['status' => AccountStatus::NeedsReconnection]);

            throw new AccountNeedsReconnection($account);
        }

        throw new RuntimeException("Slack {$method} failed: {$error}");
    }
}
