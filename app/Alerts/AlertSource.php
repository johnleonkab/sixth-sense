<?php

namespace App\Alerts;

use App\Connections\Capability;

/**
 * Where proactive alerts come from. Each source is switched on or off per user.
 */
enum AlertSource: string
{
    case Gmail = 'gmail';
    case Calendar = 'calendar';
    case Slack = 'slack';
    case Agenda = 'agenda';
    case Jira = 'jira';
    case Questions = 'questions';
    case Notion = 'notion';

    public function label(): string
    {
        return match ($this) {
            self::Gmail => 'Gmail',
            self::Calendar => 'Reuniones',
            self::Slack => 'Slack',
            self::Agenda => 'Agendador',
            self::Jira => 'Jira',
            self::Questions => 'Preguntas',
            self::Notion => 'Notion',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Gmail => 'Te aviso cuando escribe alguien que marcaste como importante, cuando responden a un hilo que empezaste o cuando el email te pide algo. Siempre te digo por qué.',
            self::Calendar => 'Te aviso antes de cada reunión, con su propio sonido. Si hay que preparar algo, hasta 30 minutos antes y te digo qué; si no, un par de minutos antes.',
            self::Jira => 'Te aviso cuando un comentario te menciona.',
            self::Agenda => 'Cuando un aviso te pide hacer algo (revisar, escribir, hablar con alguien), busco un hueco en tu calendario y lo agendo. Si es una reunión con más gente, te la propongo; si no hay hueco a tiempo, te aviso.',
            self::Slack => 'Te aviso de menciones directas y de mensajes directos.',
            self::Questions => 'Te pregunto lo que no sé: reuniones sin un propósito claro y siglas o proyectos que se repiten y no están en el archivo. Como mucho 3 al día, sin sonido. Lo que respondas queda guardado y no te lo vuelvo a preguntar.',
            self::Notion => 'Te aviso de comentarios en páginas de las que eres responsable y de tareas que te asignan.',
        };
    }

    /**
     * Accounts are only watched when this capability is on for them.
     */
    public function capability(): Capability
    {
        return match ($this) {
            self::Gmail => Capability::GmailRead,
            self::Calendar => Capability::CalendarRead,
            self::Slack => Capability::SlackSearch,
            self::Agenda => Capability::CalendarWrite,
            self::Jira => Capability::JiraRead,
            self::Questions => Capability::CalendarRead,
            self::Notion => Capability::NotionRead,
        };
    }

    /**
     * The sound an aviso of this source uses until the person picks another.
     */
    public function defaultSound(): string
    {
        return match ($this) {
            self::Slack => 'gota',
            self::Gmail => 'campana',
            self::Jira => 'toque',
            self::Notion => 'madera',
            default => 'campana',
        };
    }

    /**
     * Whether its alerts wait for the person to answer (messages), or only inform (meetings).
     */
    public function expectsAnswer(): bool
    {
        return $this !== self::Calendar;
    }
}
