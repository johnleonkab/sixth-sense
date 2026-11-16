<?php

namespace App\Comms\Models;

use App\Models\Comms\CommsEmbedding;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Gemini embeddings, cached by the text and the model. A failure returns null
 * so the caller can fall back to a string comparison.
 */
class Embeddings
{
    /**
     * @return array<array-key, float|int>|null
     */
    public function vector(string $text): ?array
    {
        try {
            return $this->fetch($text);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<array-key, float|int>  $left
     * @param  array<array-key, float|int>  $right
     */
    public static function cosine(array $left, array $right): float
    {
        if ($left === [] || $right === []) {
            return 0.0;
        }

        $dot = 0.0;
        $leftNorm = 0.0;
        $rightNorm = 0.0;

        if (array_is_list($left) && array_is_list($right)) {
            if (count($left) !== count($right)) {
                return 0.0;
            }

            foreach ($left as $index => $value) {
                $dot += (float) $value * (float) $right[$index];
                $leftNorm += (float) $value ** 2;
                $rightNorm += (float) $right[$index] ** 2;
            }
        } else {
            foreach ($left as $key => $value) {
                $leftNorm += (float) $value ** 2;

                if (array_key_exists($key, $right)) {
                    $dot += (float) $value * (float) $right[$key];
                }
            }

            foreach ($right as $value) {
                $rightNorm += (float) $value ** 2;
            }
        }

        if ($leftNorm <= 0.0 || $rightNorm <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($leftNorm) * sqrt($rightNorm));
    }

    /**
     * @return list<float>|null
     */
    private function fetch(string $text): ?array
    {
        $text = mb_substr($text, 0, 8000);

        if (trim($text) === '') {
            return null;
        }

        $model = (string) config('comms.embeddings.model');
        $hash = sha1($text);
        $cached = CommsEmbedding::query()->where('hash', $hash)->where('model', $model)->first();

        if (is_array($cached?->vector) && $cached->vector !== []) {
            return array_map(static fn (mixed $value): float => (float) $value, $cached->vector);
        }

        $key = config('ai.providers.gemini.api_key');
        $base = rtrim((string) config('ai.providers.gemini.base_url'), '/');

        if (! is_string($key) || $key === '' || $model === '' || $base === '') {
            return null;
        }

        $response = Http::withHeaders(['x-goog-api-key' => $key])
            ->connectTimeout(3)
            ->timeout(10)
            ->acceptJson()
            ->post($base.'/v1beta/models/'.rawurlencode($model).':embedContent', [
                'content' => ['parts' => [['text' => $text]]],
            ]);

        if (! $response->successful()) {
            return null;
        }

        $values = $response->json('embedding.values');

        if (! is_array($values) || $values === []) {
            return null;
        }

        $vector = array_map(static fn (mixed $value): float => (float) $value, $values);

        try {
            CommsEmbedding::query()->create([
                'hash' => $hash,
                'model' => $model,
                'vector' => $vector,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent call stored this text first.
        }

        return $vector;
    }
}
