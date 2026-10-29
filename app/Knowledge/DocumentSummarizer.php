<?php

namespace App\Knowledge;

use App\Models\User;

/**
 * Writes the short summary kept of a much used document.
 */
interface DocumentSummarizer
{
    public function summarize(User $user, string $title, string $text): string;
}
