<?php

namespace App\Comms\Ingestion;

use App\Connections\AccessTokens;
use App\Models\ConnectedAccount;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Gmail reads for ingestion. Messages are fetched in full, which does not
 * change labels. The token is the account's gmail.readonly grant.
 */
final class GmailReadClient
{
    private const URL = 'https://gmail.googleapis.com/gmail/v1/users/me/messages';

    public function __construct(private AccessTokens $tokens) {}

    /**
     * @return array<string, mixed>
     */
    public function list(ConnectedAccount $account, string $query, ?string $pageToken = null): array
    {
        $response = Http::withToken($this->tokens->for($account))
            ->acceptJson()
            ->timeout(20)
            ->get(self::URL, array_filter([
                'q' => $query,
                'maxResults' => 50,
                'pageToken' => $pageToken,
            ]));

        if ($response->failed()) {
            throw new RuntimeException('Gmail list failed with HTTP '.$response->status().'.');
        }

        return $response->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function message(ConnectedAccount $account, string $id): array
    {
        $response = Http::withToken($this->tokens->for($account))
            ->acceptJson()
            ->timeout(20)
            ->get(self::URL.'/'.rawurlencode($id), ['format' => 'full']);

        if ($response->failed()) {
            throw new RuntimeException('Gmail message failed with HTTP '.$response->status().'.');
        }

        return $response->json() ?? [];
    }
}
