<?php

namespace App\Scenes;

use App\Ai\AiGenerationContext;

/**
 * Text for scenes. Always DeepSeek in the app, so a Gemini text provider
 * cannot pick up this work. Tests replace the binding.
 */
interface SceneText
{
    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function capable(string $system, string $user, array $schema, ?AiGenerationContext $context = null): array;

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function light(string $system, string $user, array $schema, ?AiGenerationContext $context = null): array;
}
