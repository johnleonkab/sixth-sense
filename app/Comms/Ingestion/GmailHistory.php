<?php

namespace App\Comms\Ingestion;

use App\Comms\Messages\Party;
use App\Connectors\Google\GmailPayload;
use App\Connectors\Mail\MailAddress;
use App\Models\ConnectedAccount;
use Carbon\CarbonImmutable;

/**
 * Lists inbox and sent mail, then reads each message in full.
 */
class GmailHistory
{
    public function __construct(
        private GmailReadClient $gmail,
        private GmailPayload $payload,
    ) {}

    /**
     * @return list<GmailInbound>
     */
    public function collect(ConnectedAccount $account, CarbonImmutable $since): array
    {
        $query = '(in:inbox OR in:sent) -category:promotions -category:social -in:spam -in:trash after:'.$since->utc()->format('Y/m/d');
        $page = null;
        $items = [];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $list = $this->gmail->list($account, $query, $page);

            foreach ($list['messages'] ?? [] as $row) {
                if (! is_array($row) || ! isset($row['id'])) {
                    continue;
                }

                $items[] = $this->message($account, $this->gmail->message($account, (string) $row['id']));
            }

            $page = $list['nextPageToken'] ?? null;

            if (! is_string($page) || $page === '') {
                break;
            }
        }

        return array_values(array_filter($items));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function message(ConnectedAccount $account, array $data): ?GmailInbound
    {
        $id = $data['id'] ?? null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        $headers = $this->payload->headers(is_array($data['payload'] ?? null) ? $data['payload'] : []);
        $from = MailAddress::parse($headers['from'] ?? null);
        $email = filled($from?->email) ? strtolower((string) $from->email) : null;
        $labels = array_values(array_filter(
            $data['labelIds'] ?? [],
            fn (mixed $label): bool => is_string($label),
        ));

        return new GmailInbound(
            messageId: $id,
            threadId: (string) ($data['threadId'] ?? $id),
            from: new Party(
                $email ?? 'unknown',
                $email,
                $from?->name ?: ($email ?? 'unknown'),
            ),
            to: array_map(
                fn (MailAddress $address): Party => new Party(
                    strtolower((string) ($address->email ?? $address->label())),
                    filled($address->email) ? strtolower((string) $address->email) : null,
                    $address->name ?: (string) $address->email,
                ),
                MailAddress::parseList($headers['to'] ?? null),
            ),
            subject: $headers['subject'] ?? '(Sin asunto)',
            body: $this->payload->body(is_array($data['payload'] ?? null) ? $data['payload'] : []),
            at: CarbonImmutable::createFromTimestampMs((int) ($data['internalDate'] ?? 0)),
            labels: $labels,
            mine: $email !== null && strtolower((string) $account->email) === $email,
            attachments: array_column(
                $this->payload->attachments(is_array($data['payload'] ?? null) ? $data['payload'] : []),
                'name',
            ),
        );
    }
}
