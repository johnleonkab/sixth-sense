<?php

namespace App\Voice\Tools\Files;

use App\Connectors\FileKind;
use App\Voice\Tools\Canvas;
use App\Voice\Tools\ToolResult;

/**
 * Puts a file someone sent on screen in the canvas that suits it: PDFs in a
 * viewer with pages and zoom, images in an image viewer, the rest in media.
 */
final class FileCanvas
{
    /**
     * @param  string  $src  Served by Laravel with the user's credentials.
     */
    public static function result(string $name, ?string $mimeType, int $size, string $source, string $src, int $page = 1): ToolResult
    {
        $kind = FileKind::of($mimeType);
        $props = ['name' => $name, 'mime_type' => $mimeType, 'size' => $size, 'source' => $source, 'src' => $src];

        $canvas = match ($kind) {
            'pdf' => new Canvas('pdf.viewer', [...$props, 'page' => max(1, $page)], layout: 'wide'),
            'image' => new Canvas('image.viewer', $props, layout: 'wide'),
            default => new Canvas('media', [...$props, 'kind' => $kind], layout: 'wide'),
        };

        return new ToolResult(
            output: array_filter([
                'name' => $name,
                'kind' => $kind,
                'shown_on_screen' => true,
                'note' => match ($kind) {
                    'pdf' => 'Para pasar de página, ampliar, reducir o leer su texto (también en voz alta), usa control_viewer.',
                    'image' => 'Para ampliarla o reducirla, usa control_viewer.',
                    'other' => 'Este formato no se puede ver aquí: ofrece descargarlo.',
                    default => null,
                },
            ]),
            canvas: $canvas,
        );
    }
}
