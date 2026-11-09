<?php

namespace App\Voice\Tools\Context;

use App\Comms\Context\Audience;
use App\Comms\Context\ContextEngine;
use App\Comms\Context\ContextRenderer;
use App\Comms\Context\ContextRequest;
use App\Models\User;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Reads the one context store. It changes nothing.
 */
class WhatDoYouKnow implements VoiceTool
{
    public function __construct(private ContextEngine $context, private ContextRenderer $renderer) {}

    public function name(): string
    {
        return 'what_do_you_know';
    }

    public function description(): string
    {
        return 'Di lo que sabes de una persona, un área o del trabajo en general. Lee el contexto guardado y no inventes nada que no esté ahí.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'subject' => ['type' => 'string', 'description' => 'De quién o de qué. Vacío para lo general.'],
            ],
        ];
    }

    public function rules(): array
    {
        return ['subject' => ['nullable', 'string', 'max:120']];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $pack = $this->context->pack($user, new ContextRequest(Audience::Voice, about: $arguments['subject'] ?? null));
        $text = trim($this->renderer->forVoice($user)."\n".$this->renderer->rules($pack));

        return new ToolResult(['known' => $text !== '' ? $text : 'Todavía no hay nada guardado.']);
    }
}
