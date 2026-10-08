<?php

namespace App\Http\Controllers;

use App\Connectors\Chat\Chats;
use App\Connectors\FileKind;
use App\Connectors\Mail\Mailbox;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves files from connected services with the user's credentials, so the
 * browser never sees a token. Only safe formats are shown inline.
 */
class MediaController extends Controller
{
    private const INLINE = ['image', 'video', 'audio', 'pdf'];

    private const MAX_BYTES = 50_000_000;

    public function chatFile(Request $request, Chats $chats, string $file): Response
    {
        $info = $chats->file($request->user(), $file);

        abort_if($info === null, 404);
        abort_if($info->size > self::MAX_BYTES, 413, 'El archivo es demasiado grande para mostrarlo.');

        $content = $chats->download($request->user(), $file);

        abort_if($content === null, 404);

        return $this->serve($content, $info->name, $info->mimeType);
    }

    public function mailAttachment(Request $request, Mailbox $mailbox, string $message, int $index): Response
    {
        $attachment = $mailbox->message($request->user(), $message)?->attachments[$index] ?? null;

        abort_if($attachment === null, 404);
        abort_if($attachment['size'] > self::MAX_BYTES, 413, 'El archivo es demasiado grande para mostrarlo.');

        $content = $mailbox->attachment($request->user(), $message, $index);

        abort_if($content === null, 404);

        return $this->serve($content, $attachment['name'], $attachment['mime_type']);
    }

    private function serve(string $content, string $name, ?string $mimeType): Response
    {
        $inline = in_array(FileKind::of($mimeType), self::INLINE, true);

        return response($content, 200, [
            'Content-Type' => $inline ? (string) $mimeType : 'application/octet-stream',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.addcslashes($name, '"\\').'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
