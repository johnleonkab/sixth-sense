<?php

namespace App\Voice;

use App\Models\User;

interface VoiceProvider
{
    /**
     * Issue a short-lived grant the browser uses to talk to the provider directly.
     *
     * @throws VoiceProviderUnavailable
     */
    public function startSession(User $user, AssistantProfile $assistant): VoiceSession;
}
