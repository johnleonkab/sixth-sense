<?php

namespace App\Connectors\Chat;

/**
 * The reactions the assistant may propose, by their Slack name, with how
 * they look and what they say when a reaction can not be added.
 */
final class ChatReactions
{
    /** @var array<string, string> */
    public const NAMES = [
        '+1' => '👍',
        'white_check_mark' => '✅',
        'ok_hand' => '👌',
        'eyes' => '👀',
        'pray' => '🙏',
        'raised_hands' => '🙌',
        'heart' => '❤️',
    ];

    public static function emoji(string $name): string
    {
        return self::NAMES[$name] ?? '👍';
    }
}
