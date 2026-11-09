<?php

namespace App\Comms\Models;

use App\Models\Comms\CommsEvent;

final readonly class ModelAnswer
{
    /**
     * @param  array<string, mixed>|null  $output
     */
    private function __construct(
        public ModelAnswerKind $kind,
        public ?array $output,
        public CommsEvent $event,
    ) {}

    /**
     * @param  array<string, mixed>  $output
     */
    public static function accepted(array $output, CommsEvent $event): self
    {
        return new self(ModelAnswerKind::Accepted, $output, $event);
    }

    public static function question(CommsEvent $event): self
    {
        return new self(ModelAnswerKind::Question, null, $event);
    }

    public static function noDraft(CommsEvent $event): self
    {
        return new self(ModelAnswerKind::NoDraft, null, $event);
    }
}
