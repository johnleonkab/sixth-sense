<?php

namespace App\Comms\Models;

/**
 * A deterministic bag of hashed words. Equal texts score 1; texts that share
 * no words score 0. Tests never call Gemini.
 */
class FakeEmbeddings extends Embeddings
{
    /**
     * @return array<string, int>|null
     */
    public function vector(string $text): ?array
    {
        $text = mb_substr(trim($text), 0, 8000);

        if ($text === '') {
            return null;
        }

        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false || $words === []) {
            return null;
        }

        $vector = [];

        foreach ($words as $word) {
            $bucket = sprintf('%u', crc32($word));
            $vector[$bucket] = ($vector[$bucket] ?? 0) + 1;
        }

        return $vector;
    }
}
