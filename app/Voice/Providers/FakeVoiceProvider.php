<?php

namespace App\Voice\Providers;

use App\Models\User;
use App\Voice\AssistantProfile;
use App\Voice\VoiceProvider;
use App\Voice\VoiceSession;
use Carbon\CarbonImmutable;

/**
 * In-memory provider for tests. Records the sessions it starts.
 */
class FakeVoiceProvider implements VoiceProvider
{
    /**
     * @var list<array{user: User, assistant: AssistantProfile}>
     */
    public array $started = [];

    public function startSession(User $user, AssistantProfile $assistant): VoiceSession
    {
        $this->started[] = ['user' => $user, 'assistant' => $assistant];

        $now = CarbonImmutable::now();

        return new VoiceSession(
            provider: 'fake',
            token: 'fake-token-'.count($this->started),
            connectBefore: $now->addMinute(),
            expiresAt: $now->addMinutes(30),
            connection: ['instructions' => $assistant->instructions],
        );
    }
}
