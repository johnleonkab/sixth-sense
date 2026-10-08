<?php

namespace App\Voice;

use RuntimeException;

class VoiceProviderUnavailable extends RuntimeException
{
    public static function misconfigured(string $provider, string $reason): self
    {
        return new self("Voice provider [{$provider}] is misconfigured: {$reason}");
    }

    public static function failed(string $provider, string $reason): self
    {
        return new self("Voice provider [{$provider}] could not start a session: {$reason}");
    }
}
