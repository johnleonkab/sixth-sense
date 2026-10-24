<?php

namespace App\Connectors\Google;

use App\Connections\AccessTokens;
use App\Connections\Capability;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google Drive files that are not a Doc: spreadsheets and presentations,
 * plus the search that finds any of the three.
 *
 * @see https://developers.google.com/drive/api/reference/rest/v3/files/list
 * @see https://developers.google.com/sheets/api/reference/rest/v4/spreadsheets/get
 * @see https://developers.google.com/slides/api/reference/rest/v1/presentations.pages/getThumbnail
 */
class GoogleDriveFiles
{
    private const FILES_URL = 'https://www.googleapis.com/drive/v3/files';

    private const DOC = 'application/vnd.google-apps.document';

    private const SHEET = 'application/vnd.google-apps.spreadsheet';

    private const SLIDE = 'application/vnd.google-apps.presentation';

    private const FILE_FIELDS = 'nextPageToken,files(id,name,mimeType,modifiedTime,webViewLink)';

    public function __construct(private AccessTokens $tokens) {}

    /**
     * Files whose name or text matches any of the terms.
     *
     * @param  list<string>  $terms
     * @return list<array{ref: string, title: string, kind: string, account: string, url: ?string, modified_at: ?string}>
     */
    public function search(User $user, array $terms, int $limit = 15): array
    {
        $terms = $this->terms($terms);

        if ($terms === []) {
            return [];
        }

        $quoted = array_map(fn (string $term): string => $this->quote($term), $terms);
        $name = implode(' or ', array_map(fn (string $term): string => 'name contains '.$term, $quoted));
        $text = implode(' or ', array_map(fn (string $term): string => 'fullText contains '.$term, $quoted));

        return $this->list($user, $this->kinds()." and trashed = false and ({$name} or {$text})", $limit, null);
    }

    /**
     * The latest files the account can see, shared or owned, newest first.
     *
     * @return list<array{ref: string, title: string, kind: string, account: string, url: ?string, modified_at: ?string}>
     */
    public function recent(User $user, int $limit = 50): array
    {
        return $this->list($user, $this->kinds().' and trashed = false', $limit, 'modifiedTime desc');
    }

    /**
     * A sheet grid or the slide images, when the reference is one of those.
     *
     * @return array{kind: string, title: string, url: ?string, account: string, sheet?: string, rows?: list<list<string>>, slides?: list<array{number: int, src: string}>}|null
     */
    public function preview(User $user, string $reference): ?array
    {
        $located = $this->locate($user, $reference);

        if ($located === null) {
            return null;
        }

        [$account, $id, $meta] = $located;
        $kind = $this->kind($meta['mimeType'] ?? null);

        if ($kind === 'sheet') {
            return $this->sheet($account, $id, $meta);
        }

        if ($kind === 'slide') {
            return $this->slides($account, $id, $meta);
        }

        return null;
    }

    /**
     * @return list<array{ref: string, title: string, kind: string, account: string, url: ?string, modified_at: ?string}>
     */
    private function list(User $user, string $query, int $limit, ?string $orderBy): array
    {
        $accounts = [];

        foreach ($user->accountsWith(Capability::DriveRead) as $account) {
            try {
                $accounts[] = ['account' => $account, 'token' => $this->tokens->for($account)];
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if ($accounts === []) {
            return [];
        }

        $responses = Http::pool(fn (Pool $pool): array => array_map(
            fn (int $index): mixed => $pool->as((string) $index)
                ->withToken($accounts[$index]['token'])
                ->acceptJson()
                ->timeout(10)
                ->get(self::FILES_URL, array_filter([
                    'q' => $query,
                    'fields' => self::FILE_FIELDS,
                    'orderBy' => $orderBy,
                    'pageSize' => $limit,
                    'corpora' => 'allDrives',
                    'includeItemsFromAllDrives' => 'true',
                    'supportsAllDrives' => 'true',
                ])),
            array_keys($accounts),
        ));

        $files = [];

        foreach ($responses as $index => $response) {
            if (! $response instanceof Response || $response->failed()) {
                continue;
            }

            $account = $accounts[(int) $index]['account'];

            foreach ($response->json('files', []) as $file) {
                $kind = $this->kind($file['mimeType'] ?? null);

                if ($kind === null) {
                    continue;
                }

                $files[$file['id']] ??= [
                    'ref' => $account->id.'.'.$file['id'],
                    'title' => $file['name'] ?? '(Sin título)',
                    'kind' => $kind,
                    'account' => $account->email,
                    'url' => $file['webViewLink'] ?? null,
                    'modified_at' => $file['modifiedTime'] ?? null,
                ];
            }
        }

        return array_values($files);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{kind: string, title: string, url: ?string, account: string, sheet: string, rows: list<list<string>>}|null
     */
    private function sheet(ConnectedAccount $account, string $id, array $meta): ?array
    {
        if (! $account->grants(Capability::SheetsRead)) {
            return null;
        }

        try {
            $response = Http::withToken($this->tokens->for($account))->acceptJson()->timeout(15)
                ->get('https://sheets.googleapis.com/v4/spreadsheets/'.rawurlencode($id), [
                    'includeGridData' => 'true',
                    'ranges' => 'A1:L36',
                    'fields' => 'properties.title,sheets(properties.title,data.rowData.values.formattedValue)',
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $sheet = $response->json('sheets.0') ?? [];
        $rows = [];

        foreach ($sheet['data'][0]['rowData'] ?? [] as $row) {
            $cells = array_map(
                fn (array $cell): string => (string) ($cell['formattedValue'] ?? ''),
                $row['values'] ?? [],
            );
            $rows[] = $cells;
        }

        $rows = $this->trim($rows);

        return [
            'kind' => 'sheet',
            'title' => $response->json('properties.title') ?? ($meta['name'] ?? 'Hoja'),
            'url' => $meta['webViewLink'] ?? null,
            'account' => $account->email,
            'sheet' => $sheet['properties']['title'] ?? 'Hoja 1',
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{kind: string, title: string, url: ?string, account: string, slides: list<array{number: int, src: string}>}|null
     */
    private function slides(ConnectedAccount $account, string $id, array $meta): ?array
    {
        try {
            $token = $this->tokens->for($account);
            $presentation = Http::withToken($token)->acceptJson()->timeout(15)
                ->get('https://slides.googleapis.com/v1/presentations/'.rawurlencode($id), [
                    'fields' => 'title,slides.objectId',
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if ($presentation->failed()) {
            return null;
        }

        $pages = array_slice($presentation->json('slides') ?? [], 0, 12);

        if ($pages === []) {
            return null;
        }

        $responses = Http::pool(fn (Pool $pool): array => array_map(
            fn (array $page, int $index): mixed => $pool->as((string) $index)
                ->withToken($token)
                ->acceptJson()
                ->timeout(15)
                ->get('https://slides.googleapis.com/v1/presentations/'.rawurlencode($id).'/pages/'.rawurlencode($page['objectId']).'/thumbnail', [
                    'thumbnailProperties.mimeType' => 'PNG',
                    'thumbnailProperties.thumbnailSize' => 'LARGE',
                ]),
            $pages,
            array_keys($pages),
        ));

        $slides = [];

        foreach ($responses as $index => $response) {
            $src = $response instanceof Response ? $response->json('contentUrl') : null;

            if (is_string($src) && str_starts_with($src, 'https://')) {
                $slides[] = ['number' => ((int) $index) + 1, 'src' => $src];
            }
        }

        if ($slides === []) {
            return null;
        }

        return [
            'kind' => 'slide',
            'title' => $presentation->json('title') ?? ($meta['name'] ?? 'Presentación'),
            'url' => $meta['webViewLink'] ?? null,
            'account' => $account->email,
            'slides' => $slides,
        ];
    }

    /**
     * @return array{0: ConnectedAccount, 1: string, 2: array<string, mixed>}|null
     */
    private function locate(User $user, string $reference): ?array
    {
        $accounts = $user->accountsWith(Capability::DriveRead);

        if (preg_match('~^(\d+)\.([A-Za-z0-9_-]+)$~', $reference, $match)) {
            $account = $accounts->firstWhere('id', (int) $match[1]);
            $meta = $account ? $this->meta($account, $match[2]) : null;

            return $account && $meta ? [$account, $match[2], $meta] : null;
        }

        $id = $this->idFrom($reference);

        if ($id === null) {
            return null;
        }

        foreach ($accounts as $account) {
            $meta = $this->meta($account, $id);

            if ($meta !== null) {
                return [$account, $id, $meta];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function meta(ConnectedAccount $account, string $id): ?array
    {
        try {
            $response = Http::withToken($this->tokens->for($account))->acceptJson()->timeout(10)
                ->get(self::FILES_URL.'/'.rawurlencode($id), [
                    'fields' => 'id,name,mimeType,webViewLink',
                    'supportsAllDrives' => 'true',
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return $response->failed() ? null : $response->json();
    }

    private function idFrom(string $reference): ?string
    {
        if (preg_match('~docs\.google\.com/(?:document|spreadsheets|presentation)/(?:u/\d+/)?d/([A-Za-z0-9_-]+)~', $reference, $match)) {
            return $match[1];
        }

        return preg_match('~^[A-Za-z0-9_-]{20,}$~', $reference) ? $reference : null;
    }

    private function kind(?string $mime): ?string
    {
        return match ($mime) {
            self::DOC => 'doc',
            self::SHEET => 'sheet',
            self::SLIDE => 'slide',
            default => null,
        };
    }

    private function kinds(): string
    {
        return "(mimeType = '".self::DOC."' or mimeType = '".self::SHEET."' or mimeType = '".self::SLIDE."')";
    }

    /**
     * @param  list<string>  $terms
     * @return list<string>
     */
    private function terms(array $terms): array
    {
        $clean = [];

        foreach ($terms as $term) {
            $term = trim($term);

            if ($term !== '' && ! in_array($term, $clean, true)) {
                $clean[] = $term;
            }
        }

        return array_slice($clean, 0, 5);
    }

    private function quote(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }

    /**
     * @param  list<list<string>>  $rows
     * @return list<list<string>>
     */
    private function trim(array $rows): array
    {
        while ($rows !== [] && array_filter(end($rows), fn (string $cell): bool => $cell !== '') === []) {
            array_pop($rows);
        }

        return $rows;
    }
}
