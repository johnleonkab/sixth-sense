<?php

namespace App\Alerts\Replies;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * For tests and demo mode: always proposes to answer the sender.
 */
class FakeReplyPlanner implements ReplyPlanner
{
    /** @var list<AlertBrief> */
    public array $planned = [];

    public function plan(User $user, AlertBrief $brief): ReplyPlan
    {
        $this->planned[] = $brief;
        $name = strtok($brief->from, ' <') ?: $brief->from;

        return new ReplyPlan(
            summary: "Responder a {$name} que lo miras hoy.",
            reply: ['to' => 'sender', 'text' => "Hola {$name}, lo miro hoy y te digo algo."],
            otherActions: str_contains(mb_strtolower($brief->text), 'reunión') ? ['Proponer una reunión para hablarlo'] : [],
            learned: $name.' pide «'.($brief->subject ?: Str::limit($brief->text, 80)).'».',
        );
    }
}
