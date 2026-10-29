<?php

namespace App\Knowledge;

/**
 * Where a piece of knowledge was copied from.
 */
enum KnowledgeSource: string
{
    case Archive = 'archive';
    case Jira = 'jira';
    case Notion = 'notion';
    case Meeting = 'meeting';
    case Memory = 'memory';

    public function label(): string
    {
        return match ($this) {
            self::Archive => 'Archivo',
            self::Jira => 'Jira',
            self::Notion => 'Notion',
            self::Meeting => 'Reuniones',
            self::Memory => 'Memoria',
        };
    }
}
