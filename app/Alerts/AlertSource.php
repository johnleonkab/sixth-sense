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

    public function label(): string
    {
        return match ($this) {
            self::Gmail => 'Gmail',
            self::Calendar => 'Reuniones',
            self::Slack => 'Slack',
            self::Agenda => 'Agendador',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Gmail => 'Te aviso con un sonido cuando llegue un email importante. Una IA decide qué lo es (clientes, tu responsable, peticiones directas…) y te dice por qué.',
            self::Calendar => 'Te aviso antes de cada reunión, con su propio sonido. Si hay que preparar algo, hasta 30 minutos antes y te digo qué; si no, un par de minutos antes.',
            self::Agenda => 'Cuando un aviso te pide hacer algo (revisar, escribir, hablar con alguien), busco un hueco en tu calendario y lo agendo. Si es una reunión con más gente, te la propongo; si no hay hueco a tiempo, te aviso.',
            self::Slack => 'Te aviso con un sonido cuando un mensaje directo o una mención en Slack espere tu respuesta. Una IA decide cuáles lo merecen y te propone cómo responder.',
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
