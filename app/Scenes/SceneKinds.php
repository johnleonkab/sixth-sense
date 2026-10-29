<?php

namespace App\Scenes;

use App\Scenes\Kinds\AskAgent;
use App\Scenes\Kinds\Preview;
use App\Scenes\Kinds\SceneKind;
use App\Scenes\Kinds\SlotThenReply;
use App\Scenes\Kinds\StoriesFromNotes;

/**
 * The closed list of scene kinds. A model does not invent a new one.
 */
class SceneKinds
{
    public function __construct(
        private Preview $preview,
        private AskAgent $askAgent,
        private SlotThenReply $slotThenReply,
        private StoriesFromNotes $storiesFromNotes,
    ) {}

    public function find(string $type): ?SceneKind
    {
        foreach ($this->all() as $kind) {
            if ($kind->type() === $type) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * Kinds the voice may open. Preview stays out: it is only for the empty-canvas proof.
     *
     * @return list<string>
     */
    public function voiceTypes(): array
    {
        return ['slot_then_reply', 'stories_from_notes', 'ask_agent'];
    }

    /**
     * @return list<SceneKind>
     */
    private function all(): array
    {
        return [$this->preview, $this->askAgent, $this->slotThenReply, $this->storiesFromNotes];
    }
}
