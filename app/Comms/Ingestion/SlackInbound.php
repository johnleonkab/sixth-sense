<?php

namespace App\Comms\Ingestion;

use App\Comms\Messages\Party;
use Carbon\CarbonImmutable;

/**
 * One Slack message as the poller or a webhook hands it to the normalizer.
 */
final readonly class SlackInbound
{
    /**
     * @param  list<Party>  $recipients
     * @param  list<string>  $attachments
     */
    public function __construct(
        public string $teamId,
        public string $channelId,
        public string $ts,
        public string $channelKind,
        public Party $author,
        public array $recipients,
        public string $text,
        public CarbonImmutable $at,
        public ?string $threadTs = null,
        public bool $mentionsUser = false,
        public bool $userInThread = false,
        public bool $channelAllowed = false,
        public bool $mine = false,
        public ?string $subtype = null,
        public ?string $revision = null,
        public array $attachments = [],
    ) {}

    public function externalId(): string
    {
        return $this->teamId.':'.$this->channelId.':'.$this->ts;
    }

    public function threadRef(): string
    {
        return $this->threadTs ?? $this->ts;
    }
}
