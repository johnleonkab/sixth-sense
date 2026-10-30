<?php

namespace App\Models;

use Database\Factories\McpServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A remote MCP server (Streamable HTTP) the person connected. Its tools are
 * cached from the last check; read-only ones run directly, the rest wait for
 * the person's "sí".
 */
#[Fillable(['name', 'url', 'token', 'enabled', 'tools', 'checked_at', 'last_error'])]
#[Hidden(['token'])]
class McpServer extends Model
{
    /** @use HasFactory<McpServerFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'enabled' => 'boolean',
            'tools' => 'array',
            'checked_at' => 'immutable_datetime',
        ];
    }

    /**
     * One cached tool by name.
     *
     * @return array{name: string, title: ?string, description: string, input_schema: array<string, mixed>, read_only: bool}|null
     */
    public function tool(string $name): ?array
    {
        foreach ($this->tools ?? [] as $tool) {
            if (($tool['name'] ?? null) === $name) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
