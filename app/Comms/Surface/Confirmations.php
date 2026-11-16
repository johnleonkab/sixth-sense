<?php

namespace App\Comms\Surface;

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Gate\Gate;
use App\Comms\Gate\ProposalStatus;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsProposal;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The three confirmation cards. One tap answers the whole card.
 * A clear close is already done, so it has no card; undo reopens it.
 */
class Confirmations
{
    /**
     * @var array<string, string>
     */
    private const QUESTIONS = [
        'kind' => '¿Qué tipo de petición es?',
        'requesters' => '¿Quién lo pide?',
        'responders' => '¿Quién debería hacerlo?',
        'deadline' => '¿Para cuándo?',
    ];

    public function __construct(
        private Gate $gate,
        private EventLog $log,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function for(CommsIntent $intent): array
    {
        $cards = [
            ...$this->approvals($intent),
            ...$this->links($intent),
            ...$this->fields($intent),
        ];

        $outcome = $this->outcome($intent);

        if ($outcome !== null) {
            $cards[] = $outcome;
        }

        return $cards;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function answer(User $user, CommsIntent $intent, string $kind, string $answer, array $input = []): void
    {
        if ($intent->user_id !== $user->id) {
            return;
        }

        match ($kind) {
            'approve' => $this->answerApproval($intent, $answer, $input),
            'disambiguate' => $this->answerDisambiguation($user, $intent, $answer, $input),
            'outcome' => $this->answerOutcome($user, $intent, $answer),
            default => null,
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function approvals(CommsIntent $intent): array
    {
        $groups = [];

        $proposals = CommsProposal::query()
            ->where('intent_id', $intent->id)
            ->whereIn('status', [ProposalStatus::Pending, ProposalStatus::Edited])
            ->orderBy('id')
            ->get();

        foreach ($proposals as $proposal) {
            $after = $this->after(is_array($proposal->payload) ? ($proposal->payload['after'] ?? []) : []);
            $key = implode("\0", $after);
            $groups[$key][] = $proposal;
        }

        $cards = [];

        foreach ($groups as $group) {
            $cards[] = [
                'kind' => 'approve',
                'proposal_ids' => array_map(fn (CommsProposal $proposal): int => $proposal->id, $group),
                'previews' => array_map(
                    fn (CommsProposal $proposal): ?string => is_string($proposal->payload['text'] ?? null) ? $proposal->payload['text'] : null,
                    $group,
                ),
                'answers' => [
                    ['id' => 'approve', 'label' => 'Aprobar'],
                    ['id' => 'edit', 'label' => 'Editar'],
                    ['id' => 'guide', 'label' => 'Guiar'],
                    ['id' => 'reject', 'label' => 'Rechazar'],
                ],
            ];
        }

        return $cards;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function links(CommsIntent $intent): array
    {
        $cards = [];

        $links = CommsConversationLink::query()
            ->where('intent_id', $intent->id)
            ->where('uncertain', true)
            ->orderBy('id')
            ->get();

        foreach ($links as $link) {
            $cards[] = [
                'kind' => 'disambiguate',
                'link_id' => $link->id,
                'prompt' => '¿Es esto?',
                'current' => $intent->what,
                'answers' => [
                    ['id' => 'confirm', 'label' => 'Confirmar'],
                    ['id' => 'alternative', 'label' => $this->alternativeLabel($intent, $link->alternative)],
                    ['id' => 'somewhere_else', 'label' => 'En otro sitio'],
                ],
            ];
        }

        return $cards;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fields(CommsIntent $intent): array
    {
        $cards = [];
        $fields = is_array($intent->unclear_fields) ? $intent->unclear_fields : [];

        foreach ($fields as $field) {
            if (! is_string($field) || $field === '') {
                continue;
            }

            $cards[] = [
                'kind' => 'disambiguate',
                'field' => $field,
                'prompt' => self::QUESTIONS[$field] ?? '¿Qué falta aquí?',
                'answers' => [
                    ['id' => 'confirm', 'label' => 'Confirmar'],
                    ['id' => 'alternative', 'label' => 'Otra opción'],
                    ['id' => 'somewhere_else', 'label' => 'En otro sitio'],
                ],
            ];
        }

        return $cards;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function outcome(CommsIntent $intent): ?array
    {
        if ($intent->state !== 'open' || $intent->acknowledged_at === null) {
            return null;
        }

        return [
            'kind' => 'outcome',
            'answers' => [
                ['id' => 'done', 'label' => 'Hecho'],
                ['id' => 'still_open', 'label' => 'Sigue abierto'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function answerApproval(CommsIntent $intent, string $answer, array $input): void
    {
        $proposals = $this->proposals($intent, $input['proposal_ids'] ?? []);

        if ($answer === 'guide' || $proposals === []) {
            return;
        }

        if ($answer === 'edit') {
            $text = trim((string) ($input['text'] ?? ''));

            if ($text === '') {
                return;
            }

            foreach ($proposals as $proposal) {
                $this->gate->edit($proposal, $text);
            }

            return;
        }

        if ($answer === 'reject') {
            foreach ($proposals as $proposal) {
                $this->gate->reject($proposal);
            }

            return;
        }

        if ($answer === 'approve') {
            foreach ($proposals as $proposal) {
                $this->gate->approve($proposal);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function answerDisambiguation(User $user, CommsIntent $intent, string $answer, array $input): void
    {
        $field = is_string($input['field'] ?? null) ? $input['field'] : '';

        if ($field !== '') {
            if ($answer === 'confirm') {
                $this->correct($user, $intent, 'confirm_field', ['field' => $field]);
            }

            return;
        }

        $link = $this->link($user, $intent, $input['link_id'] ?? null);

        if ($link === null) {
            return;
        }

        if ($answer === 'confirm') {
            $this->correct($user, $intent, 'confirm_link', ['link_id' => $link->id]);

            return;
        }

        if ($answer === 'somewhere_else') {
            $this->correct($user, $intent, 'somewhere_else', ['link_id' => $link->id]);

            return;
        }

        if ($answer !== 'alternative') {
            return;
        }

        $target = $this->alternativeIntent($intent, $link->alternative);
        $message = $link->message;

        if ($target === null || $message === null) {
            return;
        }

        $this->correct($user, $intent, 'rematch', [
            'target_key' => $target->key,
            'message_source' => $message->source->value,
            'message_external_id' => $message->external_id,
        ]);
    }

    private function answerOutcome(User $user, CommsIntent $intent, string $answer): void
    {
        if ($answer === 'done' && $intent->state === 'open') {
            $this->correct($user, $intent, 'done');

            return;
        }

        if ($answer === 'still_open') {
            $this->correct($user, $intent, 'still_open');
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function correct(User $user, CommsIntent $intent, string $action, array $extra = []): void
    {
        $this->log->append(
            user: $user,
            type: EventType::UserCorrected,
            source: Source::User,
            externalId: $action.':'.$intent->key.':'.Str::uuid(),
            payload: null,
            normalized: [
                'action' => $action,
                'intent_key' => $intent->key,
                ...$extra,
            ],
            causation: null,
            occurredAt: now(),
        );
    }

    /**
     * @return list<CommsProposal>
     */
    private function proposals(CommsIntent $intent, mixed $ids): array
    {
        $keys = [];

        foreach (is_array($ids) ? $ids : [] as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $keys[] = (int) $id;
            }
        }

        if ($keys === []) {
            return [];
        }

        return CommsProposal::query()
            ->where('intent_id', $intent->id)
            ->whereIn('id', $keys)
            ->whereIn('status', [ProposalStatus::Pending, ProposalStatus::Edited])
            ->orderBy('id')
            ->get()
            ->all();
    }

    private function link(User $user, CommsIntent $intent, mixed $id): ?CommsConversationLink
    {
        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return null;
        }

        return CommsConversationLink::query()
            ->whereBelongsTo($user)
            ->where('intent_id', $intent->id)
            ->whereKey((int) $id)
            ->first();
    }

    private function alternativeLabel(CommsIntent $intent, mixed $alternative): string
    {
        return $this->alternativeIntent($intent, $alternative)?->what ?? $this->alternativeKey($alternative) ?? 'Otra opción';
    }

    private function alternativeIntent(CommsIntent $intent, mixed $alternative): ?CommsIntent
    {
        $key = $this->alternativeKey($alternative);

        if ($key === null) {
            return null;
        }

        return CommsIntent::query()
            ->where('user_id', $intent->user_id)
            ->where('key', $key)
            ->first();
    }

    private function alternativeKey(mixed $alternative): ?string
    {
        $value = is_array($alternative) ? ($alternative[0] ?? null) : null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function after(mixed $after): array
    {
        if (! is_array($after)) {
            return [];
        }

        $ids = array_values(array_filter($after, fn (mixed $id): bool => is_string($id) && $id !== ''));
        sort($ids);

        return $ids;
    }
}
