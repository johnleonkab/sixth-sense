<?php

namespace App\Connectors;

/**
 * What kind of file a media type is, as far as showing it goes.
 */
final class FileKind
{
    /**
     * @return 'image'|'video'|'audio'|'pdf'|'other'
     */
    public static function of(?string $mimeType): string
    {
        $type = (string) $mimeType;

        return match (true) {
            in_array($type, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true) => 'image',
            in_array($type, ['video/mp4', 'video/webm', 'video/quicktime'], true) => 'video',
            str_starts_with($type, 'audio/') => 'audio',
            $type === 'application/pdf' => 'pdf',
            default => 'other',
        };
    }
}
