<?php

namespace App\Ai;

use App\Services\PostHogService;
use Illuminate\Support\Str;

/**
 * Emits the manual AI Observability generation event for an HTTP model call.
 */
class AiObservability
{
    public function __construct(private PostHogService $posthog) {}

    /**
     * @param  list<array{role: string, content: string}>  $input
     * @param  array{input_tokens?: int|null, output_tokens?: int|null, status?: int|null, stop_reason?: string|null, latency?: float|null, temperature?: float|null, max_tokens?: int|null}  $metadata
     */
    public function capture(AiGenerationContext $context, string $provider, string $model, array $input, ?string $output, array $metadata = []): void
    {
        $properties = array_filter([
            '$ai_trace_id' => $context->traceId,
            '$ai_session_id' => $context->sessionId,
            '$ai_span_id' => (string) Str::uuid(),
            '$ai_span_name' => 'json_generation',
            '$ai_model' => $model,
            '$ai_provider' => $provider,
            '$ai_input_tokens' => $metadata['input_tokens'] ?? null,
            '$ai_output_tokens' => $metadata['output_tokens'] ?? null,
            '$ai_latency' => $metadata['latency'] ?? null,
            '$ai_http_status' => $metadata['status'] ?? null,
            '$ai_stop_reason' => $metadata['stop_reason'] ?? null,
            '$ai_temperature' => $metadata['temperature'] ?? null,
            '$ai_max_tokens' => $metadata['max_tokens'] ?? null,
            '$ai_is_error' => isset($metadata['status']) && $metadata['status'] >= 400,
        ], static fn (mixed $value): bool => $value !== null);

        // Null explicitly marks one-shot background jobs as having no conversation session.
        $properties['$ai_session_id'] = $context->sessionId;

        if (! config('posthog.ai_privacy_mode')) {
            $properties['$ai_input'] = $input;
            $properties['$ai_output_choices'] = $output === null ? [] : [['role' => 'assistant', 'content' => $output]];
        }

        $this->posthog->capture($context->distinctId, '$ai_generation', $properties);
        $this->posthog->flush();
    }
}
