<?php

namespace App\Voice\Tools\Slack;

use App\Models\User;
use App\Voice\Tools\Files\FileCanvas;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

class OpenSlackFile implements VoiceTool
{
    use UsesChats;

    public function name(): string
    {
        return 'open_slack_file';
    }

    public function description(): string
    {
        return 'Abre en pantalla un archivo enviado en Slack usando el file_id que aparece en los mensajes: los PDF en un visor con páginas y zoom, las imágenes en un visor de imágenes, y también vídeo y audio. '
            .'Si el formato no se puede ver, ofrece descargarlo.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'file_id' => ['type' => 'string', 'description' => 'file_id del archivo.'],
                'page' => ['type' => 'integer', 'description' => 'Para un PDF, la página por la que abrirlo.'],
            ],
            'required' => ['file_id'],
        ];
    }

    public function rules(): array
    {
        return ['file_id' => ['required', 'string', 'max:200'], 'page' => ['nullable', 'integer', 'min:1']];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $file = $this->chats->file($user, $arguments['file_id']);

        if ($file === null) {
            return ToolResult::error('No puedo abrir ese archivo: no existe o la cuenta no tiene permiso para archivos.');
        }

        return FileCanvas::result($file->name, $file->mimeType, $file->size, 'Slack', route('media.chat', ['file' => $file->ref], absolute: false), (int) ($arguments['page'] ?? 1));
    }
}
