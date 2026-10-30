<?php

namespace Database\Factories;

use App\Models\McpServer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<McpServer>
 */
class McpServerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->word(),
            'url' => 'https://1.1.1.1/mcp',
            'token' => 'secret-token',
            'enabled' => true,
            'tools' => [
                ['name' => 'search', 'title' => 'Buscar', 'description' => 'Busca registros.', 'input_schema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]], 'read_only' => true],
                ['name' => 'create_record', 'title' => null, 'description' => 'Crea un registro.', 'input_schema' => ['type' => 'object'], 'read_only' => false],
            ],
            'checked_at' => now(),
        ];
    }
}
