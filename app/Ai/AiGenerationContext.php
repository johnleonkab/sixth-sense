<?php

namespace App\Ai;

use App\Models\Scene;
use App\Models\User;
use App\Models\WingmanMeeting;
use Illuminate\Support\Str;

/**
 * Identity and grouping for one AI turn.
 */
final readonly class AiGenerationContext
{
    private function __construct(
        public string $distinctId,
        public ?string $sessionId,
        public string $traceId,
    ) {}

    public static function forUser(User $user): self
    {
        return new self((string) $user->getAuthIdentifier(), null, (string) Str::uuid());
    }

    public static function forMeeting(WingmanMeeting $meeting): self
    {
        return new self(
            (string) $meeting->user->getAuthIdentifier(),
            'wingman-meeting-'.$meeting->getKey(),
            (string) Str::uuid(),
        );
    }

    public static function forScene(Scene $scene): self
    {
        return new self(
            (string) $scene->user->getAuthIdentifier(),
            'scene-'.$scene->getKey(),
            (string) Str::uuid(),
        );
    }
}
