<?php

namespace App\Connectors\Slack;

/**
 * Slack's message format to readable text: <@U123> becomes @Marta, links keep
 * their label, entities are decoded.
 *
 * @see https://docs.slack.dev/messaging/formatting-message-text
 */
class SlackText
{
    /**
     * @param  callable(string): ?string  $nameOf  Slack user id to display name.
     */
    public static function readable(string $text, callable $nameOf): string
    {
        $text = (string) preg_replace_callback('/<([^>]+)>/', function (array $match) use ($nameOf): string {
            [$target, $label] = array_pad(explode('|', $match[1], 2), 2, null);

            return match (true) {
                str_starts_with($target, '@') => '@'.($label ?: $nameOf(substr($target, 1)) ?: substr($target, 1)),
                str_starts_with($target, '#') => '#'.($label ?: substr($target, 1)),
                str_starts_with($target, '!subteam') => '@'.($label ?: 'grupo'),
                str_starts_with($target, '!') => '@'.substr($target, 1),
                str_starts_with($target, 'mailto:') => $label ?: substr($target, 7),
                default => $label ?: $target,
            };
        }, $text);

        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * The web links in a message, with the label Slack shows instead of them.
     *
     * @return list<array{url: string, label: ?string}>
     */
    public static function links(string $text): array
    {
        preg_match_all('/<(https?:\/\/[^>|]+)(?:\|([^>]*))?>/', $text, $matches, PREG_SET_ORDER);

        return array_values(collect($matches)
            ->map(fn (array $match): array => [
                'url' => html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'label' => filled($match[2] ?? null) ? html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8') : null,
            ])
            ->unique('url')
            ->all());
    }

    /**
     * Escape text for Slack, keeping the mentions we add.
     */
    public static function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
