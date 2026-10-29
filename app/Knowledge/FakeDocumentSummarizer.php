<?php

namespace App\Knowledge;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * The first words, as the summary. For tests and demo mode.
 */
class FakeDocumentSummarizer implements DocumentSummarizer
{
    public function summarize(User $user, string $title, string $text): string
    {
        return Str::words(trim((string) preg_replace('/\s+/u', ' ', $text)), 30);
    }
}
