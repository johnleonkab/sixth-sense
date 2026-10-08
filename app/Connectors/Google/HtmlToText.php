<?php

namespace App\Connectors\Google;

/**
 * Turns an HTML email into readable plain text. The HTML itself is never
 * shown, so nothing in it can run or track.
 */
class HtmlToText
{
    public function convert(string $html): string
    {
        $html = (string) preg_replace('~<(head|style|script|title)\b[^>]*>.*?</\1>~is', '', $html);
        $html = (string) preg_replace('~<br\s*/?>~i', "\n", $html);
        $html = (string) preg_replace('~</(p|div|h[1-6]|tr|table|blockquote|ul|ol)>~i', "\n\n", $html);
        $html = (string) preg_replace('~<li\b[^>]*>~i', "\n• ", $html);
        $html = (string) preg_replace('~<a\b[^>]*href="(https?://[^"]+)"[^>]*>(.*?)</a>~is', '$2', $html);

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = (string) preg_replace('/[ \t]+/u', ' ', $text);
        $text = (string) preg_replace('/ *\n */u', "\n", $text);

        return trim((string) preg_replace('/\n{3,}/u', "\n\n", $text));
    }
}
