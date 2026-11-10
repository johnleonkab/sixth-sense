<?php

namespace App\Comms\Context;

use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use Illuminate\Support\Str;

/**
 * A correction on an intent teaches the next match or importance call about
 * the person who asked. Live only: replay applies the stored context events
 * instead, so this never runs twice for one correction.
 */
class CorrectionRules
{
    public function __construct(private ContextWriter $context) {}

    public function learn(CommsEvent $event): void
    {
        $data = $event->normalized ?? [];

        if ($event->user === null) {
            return;
        }

        $intent = CommsIntent::query()->whereBelongsTo($event->user)->where('key', (string) ($data['intent_key'] ?? ''))->with('participants.person')->first();
        $requester = $intent?->participants->firstWhere('role', 'requester')?->person;

        if ($intent === null || $requester === null || $requester->display_name === null) {
            return;
        }

        $name = $requester->display_name;
        $what = Str::limit((string) $intent->what, 80);
        [$layer, $sentence] = match ($data['action'] ?? '') {
            'close' => ['match', "Con {$name}, «{$what}» no era algo pendiente: lo cerró ella a mano."],
            'still_open' => ['match', "Con {$name}, una respuesta así no cerraba «{$what}»: seguía abierto."],
            'rematch', 'merge' => ['match', "Con {$name}, un mensaje sobre «{$what}» iba con otro pendiente: lo corrigió ella."],
            'split' => ['match', "Con {$name}, «{$what}» eran dos pendientes distintos."],
            'importance' => ['judge', "Lo que pide {$name} lo valora con importancia ".(int) ($data['importance'] ?? 0).' sobre 100.'],
            default => [null, null],
        };

        if ($sentence === null) {
            return;
        }

        $this->context->rule(
            $event->user,
            $sentence,
            $layer,
            $name,
            topic: ($data['action'] ?? '').':'.Str::lower($name),
            refs: ["intent:{$intent->key}", "evt:{$event->id}"],
            causation: $event,
            revision: "corrected:{$event->id}",
        );
    }
}
