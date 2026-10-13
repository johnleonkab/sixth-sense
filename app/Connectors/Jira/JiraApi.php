<?php

namespace App\Connectors\Jira;

use App\Connections\AccessTokens;
use App\Connections\AccountNeedsReconnection;
use App\Connections\AccountStatus;
use App\Connections\TokenRefreshFailed;
use App\Models\ConnectedAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Calls the Jira Cloud REST API v3 of the account's site, as the person. A
 * refused token is renewed once; a short rate limit is waited out once.
 *
 * @see https://developer.atlassian.com/cloud/jira/platform/rest/v3/
 */
class JiraApi
{
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
    public function put(ConnectedAccount $account, string $path, array $body = []): array
    {
        return $this->call($account, 'put', $path, $body);
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(ConnectedAccount $account, string $path): array
    {
        return $this->call($account, 'delete', $path, []);
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
            $response = $this->send($account, $token, $verb, $path, $data);

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
            $message = collect([...$response->json('errorMessages', []), ...array_values($response->json('errors', []))])->implode(' ')
                ?: "Jira {$path} failed with HTTP {$response->status()}";

            throw new JiraError($message, $response->status());
        }

        return $response->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(ConnectedAccount $account, string $token, string $verb, string $path, array $data): Response
    {
        $url = 'https://api.atlassian.com/ex/jira/'.$account->metadata['cloud_id'].'/rest/api/3/'.$path;
        $request = Http::withToken($token)->acceptJson()->timeout(20);

        return match ($verb) {
            'get' => $request->get($url, $data),
            'post' => $request->post($url, $data),
            'put' => $request->put($url, $data),
            default => $request->delete($url),
        };
    }
}
