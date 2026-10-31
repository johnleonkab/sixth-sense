<?php

namespace App\Alerts\Lessons;

use App\Models\ProposalOutcome;

/**
 * Turns one strong correction into short lessons: «Con Marta, tono
 * informal», «las llamadas con Pablo duran 30 minutos».
 */
interface LessonWriter
{
    /**
     * @return list<array{layer: string, about: ?string, topic: string, body: string}>
     */
    public function write(ProposalOutcome $outcome): array;
}
