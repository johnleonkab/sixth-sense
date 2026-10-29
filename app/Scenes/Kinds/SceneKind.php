<?php

namespace App\Scenes\Kinds;

use App\Models\Scene;

/**
 * One kind of scene. It knows its own two layers. It does not ask a model
 * what to do next, and it never adds a third layer.
 */
interface SceneKind
{
    public function type(): string;

    public function title(): string;

    /**
     * The empty cards shown the moment the scene opens.
     *
     * @param  array<string, mixed>  $input
     * @return list<array{key: string, title: string, state: string, text: string, error: ?string, action: ?array<string, mixed>}>
     */
    public function blank(array $input): array;

    public function run(Scene $scene): void;
}
