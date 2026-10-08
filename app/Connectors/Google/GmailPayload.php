<?php

namespace App\Connectors\Google;

/**
 * Reads a Gmail message payload: headers, plain-text body and attachments.
 */
class GmailPayload
{
    public function __construct(private HtmlToText $htmlToText) {}

    /**
     * Headers by lower-case name.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    public function headers(array $payload): array
    {
        $headers = [];

        foreach ($payload['headers'] ?? [] as $header) {
            $headers[strtolower($header['name'])] ??= $header['value'];
        }

        return $headers;
    }

    /**
     * Plain text of the email: the text part if there is one, the HTML part as text otherwise.
     *
     * @param  array<string, mixed>  $payload
     */
    public function body(array $payload): string
    {
        $parts = $this->flatten($payload);

        foreach (['text/plain', 'text/html'] as $type) {
            foreach ($parts as $part) {
                if (($part['mimeType'] ?? null) === $type && empty($part['filename']) && isset($part['body']['data'])) {
                    $content = $this->decode($part['body']['data']);

                    return trim($type === 'text/html' ? $this->htmlToText->convert($content) : str_replace("\r\n", "\n", $content));
                }
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{name: string, mime_type: ?string, size: int, id: ?string}>
     */
    public function attachments(array $payload): array
    {
        return array_map(fn (array $part): array => [
            'name' => $part['filename'],
            'mime_type' => $part['mimeType'] ?? null,
            'size' => (int) ($part['body']['size'] ?? 0),
            'id' => $part['body']['attachmentId'] ?? null,
        ], $this->attachmentParts($payload));
    }

    /**
     * The parts that are attachments, in the order attachments() lists them.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    public function attachmentParts(array $payload): array
    {
        return array_values(array_filter($this->flatten($payload), fn (array $part): bool => filled($part['filename'] ?? null)));
    }

    /**
     * @param  array<string, mixed>  $part
     * @return list<array<string, mixed>>
     */
    private function flatten(array $part): array
    {
        return [$part, ...array_merge([], ...array_map(fn (array $child): array => $this->flatten($child), $part['parts'] ?? []))];
    }

    private function decode(string $data): string
    {
        $decoded = (string) base64_decode(strtr($data, '-_', '+/'));

        return mb_check_encoding($decoded, 'UTF-8') ? $decoded : mb_convert_encoding($decoded, 'UTF-8', 'ISO-8859-1');
    }
}
