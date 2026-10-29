<?php

namespace App\Ai;

use App\Models\User;

/**
 * Picks the key a call should use: the person's own, or the system one.
 * A job pushes its owner so a worker cannot keep the previous person's key.
 */
class ProviderKeys
{
    /**
     * Frames of jobs currently running, outermost first. Each is the job id
     * and the owner's id. Empty means a web request, which uses the signed-in person.
     *
     * @var list<array{0: string, 1: ?int}>
     */
    private array $frames = [];

    public function push(string $jobId, ?int $userId): void
    {
        $this->frames[] = [$jobId, $userId];
    }

    public function pop(string $jobId): void
    {
        $top = array_key_last($this->frames);

        if ($top !== null && $this->frames[$top][0] === $jobId) {
            array_pop($this->frames);

            return;
        }

        foreach ($this->frames as $index => [$id]) {
            if ($id === $jobId) {
                array_splice($this->frames, $index, 1);

                return;
            }
        }
    }

    /**
     * The person's key for this provider, when they set one.
     */
    public function own(string $provider, ?User $user = null): ?string
    {
        $user ??= $this->owner();
        $value = match ($provider) {
            'gemini' => $user?->gemini_api_key,
            'deepseek' => $user?->deepseek_api_key,
            default => null,
        };

        return filled($value) ? $value : null;
    }

    private function owner(): ?User
    {
        if ($this->frames !== []) {
            $id = $this->frames[array_key_last($this->frames)][1];

            return $id === null ? null : User::query()->find($id);
        }

        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
