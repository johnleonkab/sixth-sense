<?php

namespace App\WorkContext;

enum WorkContextStatus: string
{
    case Idle = 'idle';
    case Running = 'running';
    case Ready = 'ready';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Idle => 'Sin generar',
            self::Running => 'Generando…',
            self::Ready => 'Listo',
            self::Failed => 'Ha fallado',
        };
    }
}
