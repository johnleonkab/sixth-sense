<?php

namespace App\Services;

use LogicException;
use PostHog\PostHog;

class PostHogService
{
    private static bool $initialized = false;

    private bool $enabled = false;

    public function __construct()
    {
        if (config('posthog.disabled')) {
            return;
        }

        $apiKey = config('posthog.api_key');
        $host = config('posthog.host');

        if (! filled($apiKey) || ! filled($host)) {
            if (config('app.debug')) {
                $variable = ! filled($apiKey) ? 'POSTHOG_PROJECT_TOKEN' : 'POSTHOG_HOST';

                throw new LogicException("{$variable} variable required by PostHog is missing or un-configured, this causes events to be silently missed. This error stops appearing once {$variable} is configured");
            }

            return;
        }

        if (! self::$initialized) {
            PostHog::init($apiKey, [
                'host' => $host,
                'error_tracking' => [
                    'enabled' => true,
                    'context_provider' => static function (array $payload): array {
                        $request = app()->bound('request') ? request() : null;

                        return [
                            'distinctId' => auth()->id() !== null ? (string) auth()->id() : null,
                            'properties' => array_filter([
                                '$request_method' => $request?->method(),
                                '$request_path' => $request?->getPathInfo(),
                                '$exception_source' => $payload['source'] ?? null,
                            ], static fn ($value): bool => $value !== null && $value !== ''),
                        ];
                    },
                ],
            ]);
            self::$initialized = true;
        }

        $this->enabled = true;
    }

    public function capture(string $distinctId, string $event, array $properties = []): void
    {
        if (! $this->enabled) {
            return;
        }

        PostHog::capture([
            'distinctId' => $distinctId,
            'event' => $event,
            'properties' => $properties,
        ]);
    }

    public function identify(string $distinctId, array $properties = []): void
    {
        if (! $this->enabled) {
            return;
        }

        PostHog::identify([
            'distinctId' => $distinctId,
            'properties' => $properties,
        ]);
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    public function contextFromHeaders(array $headers): array
    {
        return $this->enabled ? PostHog::contextFromHeaders($headers) : ['properties' => []];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function withContext(array $context, callable $callback): mixed
    {
        if (! $this->enabled) {
            return $callback();
        }

        return PostHog::withContext($context, $callback, ['fresh' => true]);
    }

    public function captureException(\Throwable $exception): void
    {
        if (! $this->enabled) {
            return;
        }

        $request = app()->bound('request') ? request() : null;

        PostHog::captureException(
            $exception,
            auth()->id() !== null ? (string) auth()->id() : null,
            array_filter([
                '$request_method' => $request?->method(),
                '$request_path' => $request?->getPathInfo(),
            ], static fn ($value): bool => $value !== null && $value !== '')
        );
    }

    public function flush(): void
    {
        if ($this->enabled) {
            PostHog::flush();
        }
    }
}
