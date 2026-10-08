<?php

namespace App\Connectors\Chat;

use App\Connectors\FileKind;

/**
 * A file shared in a conversation.
 */
final readonly class ChatFile
{
    public function __construct(
        public string $ref,
        public string $name,
        public ?string $mimeType,
        public int $size = 0,
    ) {}

    /**
     * How the media canvas can show it.
     *
     * @return 'image'|'video'|'audio'|'pdf'|'other'
     */
    public function kind(): string
    {
        return FileKind::of($this->mimeType);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['ref' => $this->ref, 'name' => $this->name, 'mime_type' => $this->mimeType, 'size' => $this->size, 'kind' => $this->kind()];
    }
}
