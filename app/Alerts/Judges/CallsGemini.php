<?php

namespace App\Alerts\Judges;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * One generateContent call, with the configured model and, when it is out of
 * quota or overloaded, the fallback model.
 *
 * @property array{api_key: ?string, base_url: string, model: string, fallback_model?: ?string, thinking_level: ?string, timeout: int} $config
 */
trait CallsGemini
{
    /**
     * @param  array<string, mixed>  $body
     */
    private function generate(array $body): Response
    {
        $models = array_values(array_unique(array_filter([$this->config['model'], $this->config['fallback_model'] ?? null])));

        foreach ($models as $position => $model) {
            $response = Http::baseUrl($this->config['base_url'])
                ->withHeaders(['x-goog-api-key' => $this->config['api_key']])
                ->timeout($this->config['timeout'])
                ->acceptJson()
                // Brief overloads pass in seconds; a spent quota does not, so it goes to the next model.
                ->retry(2, 1500, fn (Throwable $exception): bool => $exception instanceof RequestException
                    && in_array($exception->response->status(), [500, 502, 503, 504], true), throw: false)
                ->post("/v1beta/models/{$model}:generateContent", $body);

            if (! in_array($response->status(), [429, 503], true) || $position === count($models) - 1) {
                return $response;
            }
        }

        throw new \LogicException('No Gemini model configured.');
    }
}
