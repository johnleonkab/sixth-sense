<?php

namespace App\Voice\Tools\Mail;

use App\Connectors\Mail\Mailbox;
use App\Models\User;
use App\Voice\Tools\Files\FileCanvas;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;
use Illuminate\Support\Str;

/**
 * Opens a file attached to an email: a PDF, an image, a video…
 */
class OpenEmailAttachment implements VoiceTool
{
    public function __construct(private Mailbox $mailbox) {}

    public function name(): string
    {
        return 'open_email_attachment';
    }

    public function description(): string
    {
        return 'Abre en pantalla un archivo adjunto de un email: los PDF en un visor con páginas y zoom, las imágenes en un visor de imágenes. '
            .'Necesita el email_id y qué adjunto: su nombre o su número en la lista de adjuntos (1 el primero). Si el email solo tiene uno, basta con el email_id.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'email_id' => ['type' => 'string', 'description' => 'El email_id del email.'],
                'attachment' => ['type' => 'string', 'description' => 'Nombre del adjunto, o su número (1 el primero).'],
                'page' => ['type' => 'integer', 'description' => 'Para un PDF, la página por la que abrirlo.'],
            ],
            'required' => ['email_id'],
        ];
    }

    public function rules(): array
    {
        return [
            'email_id' => ['required', 'string', 'max:200'],
            'attachment' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $message = $this->mailbox->message($user, $arguments['email_id']);

        if ($message === null) {
            return ToolResult::error('No encuentro ese email.');
        }

        if ($message->attachments === []) {
            return ToolResult::error('Ese email no tiene adjuntos.');
        }

        $index = $this->indexOf($message->attachments, (string) ($arguments['attachment'] ?? ''));

        if ($index === null) {
            return ToolResult::error('Hay varios adjuntos: '.implode(', ', array_column($message->attachments, 'name')).'. Pregúntale cuál.');
        }

        $attachment = $message->attachments[$index];

        return FileCanvas::result(
            $attachment['name'],
            $attachment['mime_type'],
            $attachment['size'],
            'Gmail',
            route('media.mail', ['message' => $message->ref, 'index' => $index], absolute: false),
            (int) ($arguments['page'] ?? 1),
        );
    }

    /**
     * @param  list<array{name: string}>  $attachments
     */
    private function indexOf(array $attachments, string $wanted): ?int
    {
        $wanted = trim($wanted);

        if ($wanted === '') {
            return count($attachments) === 1 ? 0 : null;
        }

        if (ctype_digit($wanted)) {
            return isset($attachments[(int) $wanted - 1]) ? (int) $wanted - 1 : null;
        }

        $normalise = fn (string $value): string => Str::lower(Str::ascii($value));
        $matches = array_keys(array_filter($attachments, fn (array $attachment): bool => str_contains($normalise($attachment['name']), $normalise($wanted))));

        return count($matches) === 1 ? $matches[0] : null;
    }
}
