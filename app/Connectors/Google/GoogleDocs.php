<?php

namespace App\Connectors\Google;

use App\Connections\AccessTokens;
use App\Connections\Capability;
use App\Connectors\Docs\Document;
use App\Connectors\Docs\DocumentLinks;
use App\Connectors\Docs\Documents;
use App\Connectors\Docs\DocumentSearch;
use App\Connectors\Docs\DocumentSearchResult;
use App\Connectors\Docs\DocumentSummary;
use App\Connectors\Docs\EditNotPossible;
use App\Connectors\Docs\EditPlan;
use App\Connectors\Docs\WordDocumentParser;
use App\Models\ConnectedAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Google Docs: Drive to find and comment on documents, Docs to read and edit them.
 *
 * @see https://developers.google.com/drive/api/reference/rest/v3/files/list
 * @see https://developers.google.com/docs/api/reference/rest/v1/documents
 */
class GoogleDocs implements Documents
{
    private const FILES_URL = 'https://www.googleapis.com/drive/v3/files';

    private const DOCUMENTS_URL = 'https://docs.googleapis.com/v1/documents';

    private const MIME_TYPE = 'application/vnd.google-apps.document';

    /** Word files kept in Drive: they open in Docs, but the Docs API can not read them. */
    private const WORD_MIME_TYPE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private const MAX_WORD_BYTES = 15 * 1024 * 1024;

    private const FILE_FIELDS = 'nextPageToken,files(id,name,createdTime,modifiedTime,owners(displayName,emailAddress),lastModifyingUser(displayName,emailAddress),webViewLink)';

    public function __construct(private AccessTokens $tokens) {}

    public function search(User $user, DocumentSearch $search): DocumentSearchResult
    {
        $failed = [];
        $accounts = [];

        foreach ($user->accountsWith(Capability::DocsRead) as $account) {
            try {
                $accounts[] = ['account' => $account, 'token' => $this->tokens->for($account)];
            } catch (Throwable $exception) {
                report($exception);
                $failed[] = $account->email;
            }
        }

        // Owner names can not be searched in Drive: fetch more and filter here.
        $ownerName = $search->owner !== null && ! str_contains($search->owner, '@') ? $search->owner : null;

        $responses = $accounts === [] ? [] : Http::pool(fn (Pool $pool): array => array_map(
            fn (int $index): mixed => $pool->as((string) $index)
                ->withToken($accounts[$index]['token'])
                ->acceptJson()
                ->timeout(10)
                ->get(self::FILES_URL, [
                    'q' => $this->query($search),
                    'fields' => self::FILE_FIELDS,
                    'orderBy' => $search->text === null ? 'modifiedTime desc' : null,
                    'pageSize' => $ownerName ? 100 : $search->limit,
                    'corpora' => 'allDrives',
                    'includeItemsFromAllDrives' => 'true',
                    'supportsAllDrives' => 'true',
                ]),
            array_keys($accounts),
        ));

        $documents = [];
        $mayHaveMore = false;

        foreach ($responses as $index => $response) {
            $account = $accounts[(int) $index]['account'];

            if (! $response instanceof Response || $response->failed()) {
                report(new RuntimeException("Drive search failed for [{$account->email}]."));
                $failed[] = $account->email;

                continue;
            }

            $mayHaveMore = $mayHaveMore || filled($response->json('nextPageToken'));

            foreach ($response->json('files', []) as $file) {
                $summary = $this->toSummary($file, $account);

                if ($ownerName === null || Str::contains(Str::lower(Str::ascii($summary->owner['name'] ?? '')), Str::lower(Str::ascii($ownerName)))) {
                    $documents[$file['id']] ??= $summary;
                }
            }
        }

        $documents = array_values($documents);
        usort($documents, fn (DocumentSummary $a, DocumentSummary $b): int => $b->modifiedAt <=> $a->modifiedAt);

        return new DocumentSearchResult(
            documents: array_slice($documents, 0, $search->limit),
            accounts: array_values(array_diff(array_map(fn (array $item): string => $item['account']->email, $accounts), $failed)),
            failedAccounts: $failed,
            mayHaveMore: $mayHaveMore || count($documents) > $search->limit,
        );
    }

    public function find(User $user, string $reference): ?Document
    {
        $accounts = $user->accountsWith(Capability::DocsRead);

        if (preg_match('~^(\d+)\.([A-Za-z0-9_-]+)$~', $reference, $match)) {
            $account = $accounts->firstWhere('id', (int) $match[1]);

            return $account ? $this->fetch($account, $match[2]) : null;
        }

        $id = DocumentLinks::idFrom($reference);

        if ($id === null) {
            return null;
        }

        // A linked document may belong to any account: try each until one can open it.
        foreach ($accounts as $account) {
            if ($document = $this->fetch($account, $id)) {
                return $document;
            }
        }

        return null;
    }

    public function allows(User $user, string $ref, Capability $capability): bool
    {
        return $user->accountsWith($capability)->contains('id', (int) strtok($ref, '.'));
    }

    public function applyEdits(User $user, EditPlan $plan): void
    {
        [$account, $id] = $this->accountFor($user, $plan->ref, Capability::DocsEdit);

        $response = Http::withToken($this->tokens->for($account))->acceptJson()->timeout(15)
            ->post(self::DOCUMENTS_URL.'/'.rawurlencode($id).':batchUpdate', [
                'requests' => array_map(fn (array $operation): array => $operation['type'] === 'delete'
                    ? ['deleteContentRange' => ['range' => ['startIndex' => $operation['start'], 'endIndex' => $operation['end']]]]
                    : ['insertText' => ['location' => ['index' => $operation['index']], 'text' => $operation['text']]], $plan->operations),
                'writeControl' => array_filter(['requiredRevisionId' => $plan->revisionId]),
            ]);

        if ($response->status() === 400 && str_contains((string) $response->json('error.message'), 'revision')) {
            throw new EditNotPossible('El documento ha cambiado desde que propuse el cambio. Vuelve a pedírmelo.');
        }

        if ($response->failed()) {
            throw new EditNotPossible('Google Docs no ha aceptado el cambio: '.($response->json('error.message') ?? "HTTP {$response->status()}"));
        }
    }

    public function addComment(User $user, string $ref, string $content, ?string $quote = null): string
    {
        [$account, $id] = $this->accountFor($user, $ref, Capability::DocsComment);

        $response = Http::withToken($this->tokens->for($account))->acceptJson()->timeout(15)
            ->post(self::FILES_URL.'/'.rawurlencode($id).'/comments?fields=id', array_filter([
                'content' => $content,
                'quotedFileContent' => $quote ? ['mimeType' => 'text/plain', 'value' => $quote] : null,
            ]));

        if ($response->failed()) {
            throw new RuntimeException('Google Drive no ha aceptado el comentario: '.($response->json('error.message') ?? "HTTP {$response->status()}"));
        }

        return (string) $response->json('id');
    }

    /**
     * @return array{ConnectedAccount, string}
     */
    private function accountFor(User $user, string $ref, Capability $capability): array
    {
        [$accountId, $id] = array_pad(explode('.', $ref, 2), 2, '');
        $account = $user->accountsWith($capability)->firstWhere('id', (int) $accountId);

        if ($account === null || $id === '') {
            throw new EditNotPossible("Esta cuenta no tiene permiso para «{$capability->label()}». Actívalo o vuelve a conectarla en Configuración.");
        }

        return [$account, $id];
    }

    /**
     * Drive query for Google Docs matching the search.
     *
     * @see https://developers.google.com/drive/api/guides/search-files
     */
    private function query(DocumentSearch $search): string
    {
        $quote = fn (string $value): string => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
        $time = fn (CarbonImmutable $date): string => $quote($date->utc()->format('Y-m-d\TH:i:s'));

        return implode(' and ', array_filter([
            "mimeType = '".self::MIME_TYPE."'",
            'trashed = false',
            $search->name ? 'name contains '.$quote($search->name) : null,
            $search->text ? 'fullText contains '.$quote($search->text) : null,
            $search->owner && str_contains($search->owner, '@') ? $quote($search->owner).' in owners' : null,
            $search->modifiedAfter ? 'modifiedTime >= '.$time($search->modifiedAfter) : null,
            $search->modifiedBefore ? 'modifiedTime < '.$time($search->modifiedBefore->addDay()) : null,
            $search->createdAfter ? 'createdTime >= '.$time($search->createdAfter) : null,
            $search->createdBefore ? 'createdTime < '.$time($search->createdBefore->addDay()) : null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $file
     */
    private function toSummary(array $file, ConnectedAccount $account): DocumentSummary
    {
        $person = fn (?array $user): ?array => $user ? ['name' => $user['displayName'] ?? null, 'email' => $user['emailAddress'] ?? null] : null;

        return new DocumentSummary(
            ref: $account->id.'.'.$file['id'],
            account: $account->email,
            title: $file['name'] ?? '(Sin título)',
            owner: $person($file['owners'][0] ?? null),
            createdAt: CarbonImmutable::parse($file['createdTime']),
            modifiedAt: CarbonImmutable::parse($file['modifiedTime']),
            lastModifiedBy: $person($file['lastModifyingUser'] ?? null),
            url: $file['webViewLink'] ?? null,
        );
    }

    private function fetch(ConnectedAccount $account, string $id): ?Document
    {
        try {
            $response = Http::withToken($this->tokens->for($account))->acceptJson()->timeout(15)
                ->get(self::DOCUMENTS_URL.'/'.rawurlencode($id));
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        // "This operation is not supported for this document": it may be a Word file.
        if ($response->status() === 400) {
            return $this->fetchWordFile($account, $id);
        }

        if ($response->failed()) {
            return null;
        }

        return (new GoogleDocParser)->parse($response->json(), $account->id.'.'.$id, (string) $account->email);
    }

    /**
     * A Word file in Drive, read-only. Downloading it needs a Drive scope.
     */
    private function fetchWordFile(ConnectedAccount $account, string $id): ?Document
    {
        if (! $account->grants(Capability::DriveRead) && ! $account->grants(Capability::DocsComment)) {
            return null;
        }

        try {
            $request = Http::withToken($this->tokens->for($account))->timeout(30);
            $file = $request->acceptJson()->get(self::FILES_URL.'/'.rawurlencode($id), [
                'fields' => 'id,name,mimeType,size,webViewLink',
                'supportsAllDrives' => 'true',
            ]);

            if ($file->failed() || $file->json('mimeType') !== self::WORD_MIME_TYPE || (int) $file->json('size') > self::MAX_WORD_BYTES) {
                return null;
            }

            $content = $request->get(self::FILES_URL.'/'.rawurlencode($id), ['alt' => 'media', 'supportsAllDrives' => 'true']);

            if ($content->failed()) {
                return null;
            }

            return (new WordDocumentParser)->parse($content->body(), $account->id.'.'.$id, (string) $account->email, (string) $file->json('name'), $file->json('webViewLink'));
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
