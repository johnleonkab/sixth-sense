<?php

namespace App\Comms\Context;

use App\Models\User;
use App\WorkContext\WorkDigest;
use Carbon\CarbonImmutable;

/**
 * One block for every reader. The headers the existing prompts already
 * expect stay, so a layer does not change voice when it moves here.
 */
class ContextRenderer
{
    public function __construct(private ContextEngine $context) {}

    public function rules(ContextPack $pack): string
    {
        if ($pack->rules === []) {
            return '';
        }

        return "\nAprendido de avisos anteriores (datos):\n- ".implode("\n- ", array_column($pack->rules, 'text'));
    }

    public function triage(User $user, ContextPack $pack): string
    {
        $parts = [];

        if ($pack->summary !== '') {
            $parts[] = $pack->summary;
        }

        if ($pack->attention !== '') {
            $parts[] = "Política de atención en Slack, aprendida de cómo ha respondido:\n".$pack->attention;
        }

        if ($pack->rules !== []) {
            $parts[] = "Aprendido al resolver avisos anteriores:\n".implode("\n", array_column($pack->rules, 'text'));
        }

        return implode("\n\n", $parts);
    }

    public function forVoice(User $user): string
    {
        $context = $user->workContext;

        if ($context !== null && $context->usable()) {
            return WorkDigest::fromArray($context->digest ?? ['summary' => $context->summary])->forAssistant(
                $context->generated_at ?? CarbonImmutable::now(),
            );
        }

        $pack = $this->context->pack($user, new ContextRequest(Audience::Voice));

        return $pack->summary === '' ? '' : $pack->summary;
    }
}
