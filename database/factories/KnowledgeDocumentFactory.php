<?php

namespace Database\Factories;

use App\Knowledge\KnowledgeSource;
use App\Models\KnowledgeDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeDocument>
 */
class KnowledgeDocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'source' => KnowledgeSource::Archive,
            'ref' => 'projects/'.fake()->unique()->slug(2).'.md',
            'title' => fake()->sentence(4),
            'url' => fake()->url(),
            'version' => fake()->sha1(),
            'content' => fake()->paragraphs(3, true),
            'indexed_at' => now(),
            'seen_at' => now(),
        ];
    }
}
