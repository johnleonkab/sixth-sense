<?php

namespace App\Comms\Cutover;

use App\Actions\ActionStatus;
use App\Comms\Events\Source;
use App\Comms\Gate\ActionClass;
use App\Comms\Gate\ProposalStatus;
use App\Comms\Pipeline;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsProposal;
use App\Models\Comms\CommsQuestion;
use App\Models\User;

/**
 * The proposal or question the voice should read while a source is cut over.
 */
class OpenItem
{
    public function __construct(private Pipeline $pipeline) {}

    /**
     * @return array{proposal_id: int|null, question: string|null, undo: bool}|null
     */
    public function for(User $user): ?array
    {
        if (! $this->pipeline->enabled('slack') && ! $this->pipeline->enabled('gmail')) {
            return null;
        }

        $proposal = $this->proposal($user);
        $question = $this->question($user);

        if ($proposal === null && $question === null) {
            return null;
        }

        return [
            'proposal_id' => $proposal?->id,
            'question' => $question?->text,
            'undo' => $proposal?->pendingAction?->status === ActionStatus::Confirmed,
        ];
    }

    public function say(User $user): string
    {
        $open = $this->for($user);

        if ($open === null) {
            return '';
        }

        $lines = [];

        if (is_string($open['question']) && $open['question'] !== '') {
            $lines[] = 'Pregunta abierta: '.$open['question'];
        }

        if (is_int($open['proposal_id'])) {
            $text = CommsProposal::query()->find($open['proposal_id'])?->payload['text'] ?? '';
            $lines[] = 'Propuesta sin enviar'.(is_string($text) && $text !== '' ? ': '.$text : '').'. Si dice sí, la aplicación la aprueba. Si dice cancela, la rechaza.';
        }

        return implode(' ', $lines);
    }

    private function proposal(User $user): ?CommsProposal
    {
        $proposals = CommsProposal::query()
            ->whereBelongsTo($user)
            ->whereIn('status', [ProposalStatus::Pending, ProposalStatus::Edited])
            ->with('pendingAction')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return $proposals->first(function (CommsProposal $proposal): bool {
            $source = match ($proposal->action_class) {
                ActionClass::SendSlack => 'slack',
                ActionClass::SendEmailReply, ActionClass::SendEmailNew => 'gmail',
                default => null,
            };

            return $source !== null && $this->pipeline->enabled($source);
        });
    }

    private function question(User $user): ?CommsQuestion
    {
        $questions = CommsQuestion::query()
            ->whereBelongsTo($user)
            ->whereNull('answer')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        foreach ($questions as $question) {
            if ($question->intent_id === null) {
                continue;
            }

            $source = CommsMessage::query()
                ->whereIn('id', CommsConversationLink::query()->where('intent_id', $question->intent_id)->pluck('message_id'))
                ->value('source');
            $value = $source instanceof Source ? $source->value : (is_string($source) ? $source : null);

            if ($value !== null && $this->pipeline->enabled($value)) {
                return $question;
            }
        }

        return null;
    }
}
