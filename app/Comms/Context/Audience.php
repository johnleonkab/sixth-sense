<?php

namespace App\Comms\Context;

/**
 * Who is asking. A fact with an empty audience list is readable by all of them.
 */
enum Audience: string
{
    case Voice = 'voice';
    case Importance = 'importance';
    case Triage = 'triage';
    case Classifier = 'classifier';
    case Match = 'match';
    case Reply = 'reply';
    case Scheduler = 'scheduler';
    case Export = 'export';

    /** The layers a correction can teach, as the rule writer names them. */
    public const LAYERS = ['judge', 'triage', 'classifier', 'reply', 'scheduler'];

    public static function fromLayer(?string $layer): self
    {
        return match ($layer) {
            'judge' => self::Importance,
            'triage' => self::Triage,
            'classifier' => self::Classifier,
            'scheduler' => self::Scheduler,
            'reply' => self::Reply,
            'match' => self::Match,
            default => self::Voice,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Voice => 'Voz',
            self::Importance => 'Importancia',
            self::Triage => 'A quién le toca',
            self::Classifier => 'Qué se pide',
            self::Match => 'Emparejar mensajes',
            self::Reply => 'Respuestas',
            self::Scheduler => 'Agenda',
            self::Export => 'Archivo de trabajo',
        };
    }

    /**
     * The kinds of fact this reader gets.
     *
     * @return list<string>
     */
    public function kinds(): array
    {
        return match ($this) {
            self::Importance => ['person', 'area', 'channel', 'rule'],
            self::Triage => ['person', 'channel', 'area', 'rule', 'summary'],
            self::Classifier => ['person', 'rule'],
            self::Match => ['person', 'preference', 'rule'],
            self::Reply => ['person', 'preference', 'workstream', 'term', 'rule', 'summary'],
            self::Scheduler => ['preference', 'workstream', 'rule', 'summary'],
            self::Voice, self::Export => ['person', 'area', 'preference', 'channel', 'workstream', 'rule', 'term', 'summary'],
        };
    }

    /**
     * Tokens a pack may take in the prompt. Null: no limit.
     */
    public function budget(): ?int
    {
        return match ($this) {
            self::Voice => 1500,
            self::Importance, self::Triage, self::Scheduler => 400,
            self::Classifier, self::Match => 300,
            self::Reply => 800,
            self::Export => null,
        };
    }

    /**
     * Sensitive facts never reach the voice or the work archive.
     */
    public function readsSensitive(): bool
    {
        return ! in_array($this, [self::Voice, self::Export], true);
    }
}
