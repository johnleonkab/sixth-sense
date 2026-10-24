<?php

namespace App\Scenes\Kinds;

use App\Models\Scene;
use App\Scenes\SceneBoard;

/**
 * A stand-in kind with no model: two cards fill on their own.
 * It is not offered to the voice. It proves the empty screen fills later.
 */
class Preview implements SceneKind
{
    public function __construct(private SceneBoard $board) {}

    public function type(): string
    {
        return 'preview';
    }

    public function title(): string
    {
        return 'Escena de prueba';
    }

    public function blank(array $input): array
    {
        return [
            SceneBoard::card('una', 'Primera'),
            SceneBoard::card('otra', 'Segunda'),
        ];
    }

    public function run(Scene $scene): void
    {
        $this->board->fill($scene, 'una', ['state' => 'ready', 'text' => 'Primera pieza.']);
        $scene->refresh();
        $this->board->fill($scene, 'otra', ['state' => 'ready', 'text' => 'Segunda pieza.']);
    }
}
