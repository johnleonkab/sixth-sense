<?php

namespace App\Knowledge;

/**
 * What the knowledge base keeps of a document instead of its text: each
 * distinct word once, in no particular order. Enough to find the document,
 * not to read it. Acronyms keep their case so they can still be noticed.
 */
class KnowledgeTerms
{
    /** Words kept per document, at most. */
    private const MAX_TERMS = 4000;

    /** Words too common to help find anything. */
    private const STOPWORDS = [
        'que', 'qué', 'como', 'cómo', 'cual', 'cuál', 'cuando', 'cuándo', 'donde', 'dónde', 'para', 'por', 'con', 'sin', 'sobre',
        'los', 'las', 'del', 'una', 'uno', 'unos', 'unas', 'este', 'esta', 'esto', 'ese', 'esa', 'eso', 'estos', 'estas', 'hay',
        'hace', 'tiene', 'son', 'fue', 'ser', 'más', 'mas', 'pero', 'porque', 'algo', 'todo', 'todos', 'nos', 'les', 'sus', 'muy',
        'ya', 'también', 'entre', 'hasta', 'desde', 'cada', 'otro', 'otra', 'puede', 'tienen', 'están', 'está', 'han', 'hemos',
        'the', 'and', 'for', 'with', 'that', 'this', 'from', 'are', 'was', 'have', 'has', 'you', 'not', 'but', 'what', 'how',
    ];

    /**
     * The index of a text: its distinct words separated by spaces.
     */
    public static function of(string $text): string
    {
        preg_match_all('/[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*/u', $text, $matches);

        $terms = [];

        foreach ($matches[0] as $word) {
            $acronym = preg_match('/^[A-Z][A-Z0-9]{1,7}$/', $word) === 1;
            $term = $acronym ? $word : mb_strtolower($word);

            if ((! $acronym && mb_strlen($term) < 3) || mb_strlen($term) > 40 || in_array($term, self::STOPWORDS, true) || preg_match('/^\d+$/', $term) === 1) {
                continue;
            }

            $terms[$term] = true;

            if (count($terms) >= self::MAX_TERMS) {
                break;
            }
        }

        return implode(' ', array_keys($terms));
    }
}
