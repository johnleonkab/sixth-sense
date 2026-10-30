<?php

namespace App\Voice\OpenUi\Data;

use App\Connectors\Mcp\McpError;
use App\Connectors\Mcp\McpServers;
use App\Models\User;
use RuntimeException;

/**
 * What a read-only tool of a connected MCP server returns, as rows: the
 * first list of records in its answer, each flattened to plain fields. A tool
 * that could change something is never called from here.
 */
class McpRows implements Dataset
{
    public function __construct(private McpServers $servers) {}

    public function name(): string
    {
        return 'mcp_rows';
    }

    public function description(): string
    {
        return 'Filas que devuelve una herramienta de solo lectura de un servidor MCP conectado (server, tool y sus arguments, como en mcp_tools). Los campos dependen de la herramienta; mira las filas antes de agruparlas.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'server' => ['type' => 'string'],
                'tool' => ['type' => 'string'],
                'arguments' => ['type' => 'object'],
            ],
            'required' => ['server', 'tool'],
        ];
    }

    public function rules(): array
    {
        return [
            'server' => ['required', 'string', 'max:120'],
            'tool' => ['required', 'string', 'max:120'],
            'arguments' => ['nullable', 'array'],
        ];
    }

    public function fields(): array
    {
        return [];
    }

    public function rows(User $user, array $arguments, string $timezone): array
    {
        $server = $this->servers->named($user, $arguments['server']);
        $tool = $server?->tool($arguments['tool']);

        if ($server === null || $tool === null) {
            throw new RuntimeException("No hay una herramienta «{$arguments['tool']}» en un servidor MCP «{$arguments['server']}» activo.");
        }

        if (! $tool['read_only']) {
            throw new RuntimeException("«{$tool['name']}» puede cambiar cosas: desde una vista solo se leen datos.");
        }

        try {
            $result = $this->servers->call($server, $tool['name'], $arguments['arguments'] ?? []);
        } catch (McpError $error) {
            throw new RuntimeException($error->getMessage());
        }

        if ($result['is_error']) {
            throw new RuntimeException("«{$tool['name']}» respondió con un error.");
        }

        $records = self::records($result['structured'] ?? json_decode($result['text'], true));

        return array_map(self::flatten(...), array_slice($records, 0, Datasets::MAX_ROWS));
    }

    /**
     * The first list of records in an answer: the answer itself, or the first
     * of its fields that is one ({"items": […]}, {"data": {"rows": […]}}).
     *
     * @return list<array<string, mixed>>
     */
    public static function records(mixed $answer, int $depth = 0): array
    {
        if (! is_array($answer) || $depth > 3) {
            return [];
        }

        if (array_is_list($answer)) {
            return array_values(array_filter($answer, is_array(...)));
        }

        foreach ($answer as $value) {
            $found = self::records($value, $depth + 1);

            if ($found !== []) {
                return $found;
            }
        }

        return [];
    }

    /**
     * One record as plain fields: nested ones joined by dots, lists as text.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, string|int|float|bool|null>
     */
    public static function flatten(array $record, string $prefix = ''): array
    {
        $fields = [];

        foreach ($record as $key => $value) {
            $name = $prefix.$key;

            if (is_array($value) && ! array_is_list($value) && $prefix === '') {
                $fields += self::flatten($value, "{$name}.");
            } elseif (is_array($value)) {
                $fields[$name] = mb_substr((string) json_encode($value, JSON_UNESCAPED_UNICODE), 0, 200);
            } else {
                $fields[$name] = is_string($value) ? mb_substr($value, 0, 500) : $value;
            }
        }

        return $fields;
    }
}
