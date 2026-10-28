<?php

namespace App\Alerts\Intents;

/**
 * What Slack triage asks the person to do with one message.
 */
enum SlackAction: string
{
    case Reply = 'reply';
    case Redirect = 'redirect';
    case Task = 'task';
    case Notice = 'notice';
    case Ignore = 'ignore';

    public function shown(): bool
    {
        return match ($this) {
            self::Reply, self::Redirect, self::Task => true,
            self::Notice, self::Ignore => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Reply => 'Responde tú',
            self::Redirect => 'Le toca a otro',
            self::Task => 'Tarea',
            self::Notice => 'Solo para saber',
            self::Ignore => 'Ignorar',
        };
    }

    public static function parse(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }
}
