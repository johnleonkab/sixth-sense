<?php

namespace App\Comms\Gate\WriteTools;

use App\Connections\Capability;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\OutgoingChatMessage;
use App\Models\Comms\CommsProposal;
use App\Models\User;
use RuntimeException;

/**
 * Sends one Slack message. A second call with the same external id does not send again.
 */
class SendSlackMessage
{
    public function __construct(private Chats $chats) {}

    public function send(User $user, CommsProposal $proposal): string
    {
        if (filled($proposal->external_id)) {
            return $proposal->external_id;
        }

        $payload = $proposal->payload;
        $thread = $payload['thread_ref'] ?? $payload['thread_id'] ?? ($proposal->target['thread'] ?? null);

        return $this->chats->send($user, new OutgoingChatMessage(
            to: $this->conversation($user, (string) ($payload['to'] ?? '')),
            toLabel: (string) ($payload['to_label'] ?? $payload['to'] ?? 'hilo'),
            text: (string) ($payload['text'] ?? ''),
            threadRef: filled($thread) ? (string) $thread : null,
        ));
    }

    /**
     * Slack chats are addressed as "{account id}:{channel}". A proposal keeps
     * the bare channel id, so the account that can send is added here.
     */
    private function conversation(User $user, string $to): string
    {
        if ($to === '') {
            throw new RuntimeException('No hay una cuenta de Slack con permiso para enviar. Actívalo en Configuración y vuelve a conectarla.');
        }

        if (preg_match('/^\d+:/', $to) === 1) {
            return $to;
        }

        $account = $user->accountsWith(Capability::SlackSend)->first();

        return $account === null ? $to : $account->id.':'.$to;
    }
}
