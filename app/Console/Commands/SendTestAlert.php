<?php

namespace App\Console\Commands;

use App\Alerts\AlertFollowUp;
use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Connections\Capability;
use App\Connectors\Slack\SlackApi;
use App\Connectors\Slack\SlackChats;
use App\Events\InboxItemCreated;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('alerts:test {email : The user to alert}')]
#[Description('Raise a test Slack alert in the user\'s own direct message (notes to self), so answering it only reaches them')]
class SendTestAlert extends Command
{
    private const TEXT = 'Hola, ¿te viene bien el jueves a las 10 para revisar con Marta el flujo de prepago? Si no, proponme otra hora y te mando la invitación.';

    /**
     * Execute the console command.
     */
    public function handle(SlackApi $slack, SlackChats $chats, AlertFollowUp $followUp): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        $account = $user?->accountsWith(Capability::SlackSend)->first();

        if ($account === null) {
            $this->components->error('Ese usuario no tiene ninguna cuenta de Slack que pueda enviar mensajes.');

            return self::FAILURE;
        }

        // The direct message with oneself: a reply sent while testing only reaches the user.
        $channel = $slack->post($account, 'conversations.open', ['users' => $chats->me($account)])['channel']['id'];
        $ts = now()->format('U.u');

        $item = $user->inboxItems()->create([
            'source' => AlertSource::Slack,
            'external_id' => "{$account->id}:{$channel}:{$ts}",
            'thread_key' => "{$account->id}:{$channel}",
            'actor_name' => 'Prueba de sixth-sense',
            'title' => 'Mensaje directo',
            'body' => self::TEXT,
            'why' => 'Te propone revisar el flujo de prepago el jueves a las 10 y espera tu confirmación.',
            'relevance' => 80,
            'status' => AlertStatus::Pending,
            'payload' => ['account' => $account->email, 'kind' => 'dm', 'test' => true],
            'received_at' => now(),
        ]);

        InboxItemCreated::dispatch($item);
        $this->components->info("Alerta {$item->id} lanzada. Pensando la propuesta…");

        try {
            $followUp->plan($item);
            $this->components->twoColumnDetail('Propuesta', $item->refresh()->proposal['summary'] ?? '—');
        } catch (Throwable $exception) {
            $this->components->warn("No se ha podido preparar la propuesta: {$exception->getMessage()}");
        }

        return self::SUCCESS;
    }
}
