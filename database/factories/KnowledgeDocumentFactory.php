<?php

namespace Database\Factories;

use App\Knowledge\KnowledgeSource;
use App\Knowledge\KnowledgeTerms;
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
            'terms' => KnowledgeTerms::of(fake()->paragraphs(3, true)),
            'indexed_at' => now(),
            'seen_at' => now(),
        ];
    }

    /**
     * Indexed from this text, as the sync would index it.
     */
    public function text(string $text): self
    {
        return $this->state(fn (): array => ['terms' => KnowledgeTerms::of($text)]);
    }
}
