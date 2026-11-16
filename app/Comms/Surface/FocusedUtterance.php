<?php

namespace App\Comms\Surface;

use App\Comms\Gate\ProposalStatus;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsProposal;
use App\Models\User;

/**
 * A spoken sentence while a proposal is open. "sí" confirms it.
 * Any other sentence rewrites the draft and leaves it unsent.
 */
class FocusedUtterance
{
    /**
     * The whole phrase is only the yes, the same words the fast loop already owns.
     *
     * @var list<string>
     */
    private const YES = [
        'si',
        'vale',
        'ok',
        'okay',
        'adelante',
        'hazlo',
        'dale',
        'confirmo',
        'confirma',
        'confirmado',
        'aplicalo',
        'aplica',
        'aplicar',
        'perfecto',
        'correcto',
        'de acuerdo',
        'publicalo',
        'publica',
        'envialo',
        'envia',
        'enviarlo',
        'mandalo',
        'manda',
        'mandarlo',
    ];

    public function __construct(private Confirmations $confirmations) {}

    /**
     * @param  list<int>  $proposalIds
     * @return 'yes'|'guidance'|'none'
     */
    public function hear(User $user, CommsIntent $intent, string $transcript, array $proposalIds = []): string
    {
        if ($intent->user_id !== $user->id) {
            return 'none';
        }

        $ids = $this->openIds($intent, $proposalIds);
        $text = trim($transcript);

        if ($ids === [] || $text === '') {
            return 'none';
        }

        if ($this->confirms($text)) {
            $this->confirmations->answer($user, $intent, 'approve', 'approve', ['proposal_ids' => $ids]);

            return 'yes';
        }

        $this->confirmations->answer($user, $intent, 'approve', 'guide', [
            'proposal_ids' => $ids,
            'text' => $text,
        ]);

        return 'guidance';
    }

    /**
     * @param  list<int>  $proposalIds
     * @return list<int>
     */
    private function openIds(CommsIntent $intent, array $proposalIds): array
    {
        $open = CommsProposal::query()
            ->where('intent_id', $intent->id)
            ->whereIn('status', [ProposalStatus::Pending, ProposalStatus::Edited])
            ->orderBy('id')
            ->get();

        if ($proposalIds !== []) {
            $wanted = array_map(intval(...), $proposalIds);

            return $open
                ->filter(fn (CommsProposal $proposal): bool => in_array($proposal->id, $wanted, true))
                ->map(fn (CommsProposal $proposal): int => $proposal->id)
                ->values()
                ->all();
        }

        foreach ($this->confirmations->for($intent) as $card) {
            $ids = $card['proposal_ids'] ?? null;

            if (($card['kind'] ?? '') === 'approve' && is_array($ids) && $ids !== []) {
                return array_map(intval(...), $ids);
            }
        }

        return [];
    }

    private function confirms(string $transcript): bool
    {
        $words = mb_strtolower($transcript);
        $words = strtr($words, [
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'ü' => 'u',
            'ñ' => 'n',
        ]);
        $words = preg_replace('/[¿?¡!.,;:"«»]/u', ' ', $words) ?? $words;
        $words = trim(preg_replace('/\s+/u', ' ', $words) ?? $words);

        return in_array($words, self::YES, true);
    }
}
