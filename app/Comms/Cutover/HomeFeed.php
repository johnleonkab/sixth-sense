<?php

namespace App\Comms\Cutover;

use App\Alerts\AlertFeed;
use App\Alerts\AlertSource;
use App\Comms\Pipeline;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsNotification;
use App\Models\Comms\CommsProposal;
use App\Models\Comms\CommsQuestion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The home list: old alerts for sources still on the old brain, and
 * communications notifications for sources that have been cut over.
 */
class HomeFeed
{
    public function __construct(private AlertFeed $alerts, private Pipeline $pipeline) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function items(User $user): array
    {
        $kept = array_values(array_filter(
            $this->alerts->for($user),
            fn (array $alert): bool => ! $this->pipeline->enabled((string) $alert['source']),
        ));
        $items = [...$kept, ...$this->cards($user)];

        usort($items, fn (array $left, array $right): int => AlertFeed::priority($left) <=> AlertFeed::priority($right));

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cards(User $user): array
    {
        $cards = [];
        $notifications = CommsNotification::query()
            ->whereBelongsTo($user)
            ->whereNull('dismissed_at')
            ->orderByDesc('id')
            ->limit(40)
            ->get();

        foreach ($notifications as $notification) {
            $source = $this->pipeline->sourceOf($notification);

            if ($source === null || ! $this->pipeline->enabled($source)) {
                continue;
            }

            $cards[] = $this->card($notification);
        }

        return $cards;
    }

    /**
     * @return array<string, mixed>
     */
    public function card(CommsNotification $notification): array
    {
        $source = $this->pipeline->sourceOf($notification) ?? 'slack';
        $intent = $notification->subject_type === 'intent'
            ? CommsIntent::query()->with('participants.person')->find($notification->subject_id)
            : null;
        $requester = $intent?->participants->firstWhere('role', 'requester')?->person?->display_name;
        $text = $this->text($notification, $intent);
        $waiting = in_array($notification->kind, ['question', 'proposal'], true)
            || ($intent !== null && $intent->state !== 'settled');
        $pending = $notification->dismissed_at === null && $waiting;
        $received = ($notification->created_at ?? now())->toIso8601String();

        return [
            'id' => -$notification->id,
            'source' => $source,
            'source_label' => AlertSource::from($source)->label(),
            'actor_name' => $requester ?? 'sixth-sense',
            'title' => Str::limit($text, 120, ''),
            'why' => $this->why($notification, $intent),
            'received_at' => $received,
            'external_id' => 'comms:'.$notification->id,
            'external_url' => null,
            'account' => null,
            'starts_at' => null,
            'meeting_url' => null,
            'event_ref' => null,
            'status' => $notification->dismissed_at !== null ? 'dismissed' : ($pending ? 'pending' : 'notice'),
            'state' => $notification->dismissed_at !== null ? 'dismissed' : match ($notification->kind) {
                'question' => 'question',
                'proposal' => 'ready',
                default => $notification->urgency === 'now' ? 'important' : 'routine',
            },
            'resolved_at' => $notification->dismissed_at?->toIso8601String(),
            'resolution' => $notification->dismissed_at !== null ? 'dismissed' : null,
            'deadline' => $intent?->deadline?->toDateString(),
            'blocking' => $notification->urgency === 'now',
            'quiet' => $notification->urgency !== 'now',
            'messages' => $this->messages($intent) ?: [[
                'id' => 'comms:'.$notification->id,
                'from' => 'sixth-sense',
                'text' => $text,
                'at' => $received,
            ]],
            'proposal' => $notification->kind === 'proposal' ? ['summary' => $text] : null,
            'task' => null,
            'comms' => true,
        ];
    }

    /**
     * Why it is on the list, in a line: by when, and whether it is urgent. Who
     * asks is already the card's actor.
     */
    private function why(CommsNotification $notification, ?CommsIntent $intent): string
    {
        if ($intent === null) {
            return match ($notification->kind) {
                'question' => 'Necesito que me lo confirmes.',
                'proposal' => 'Tengo una propuesta lista para enviar.',
                'batch' => 'Llevan días sin moverse.',
                default => $notification->urgency === 'now' ? 'Quiere tu atención.' : 'Para el resumen.',
            };
        }

        $line = $intent->deadline !== null
            ? 'Lo necesita para el '.CarbonImmutable::parse($intent->deadline)->locale('es')->isoFormat('dddd D [de] MMMM').'.'
            : 'Espera tu respuesta.';

        return ($intent->flags['urgent'] ?? false) === true ? 'Urgente. '.$line : $line;
    }

    /**
     * The messages the intent came from, oldest first.
     *
     * @return list<array{id: string, from: string, text: string, at: string}>
     */
    private function messages(?CommsIntent $intent): array
    {
        if ($intent === null) {
            return [];
        }

        return $intent->links()
            ->with('message.author')
            ->latest('id')
            ->limit(5)
            ->get()
            ->reverse()
            ->filter(fn ($link): bool => $link->message !== null)
            ->map(fn ($link): array => [
                'id' => 'comms-message:'.$link->message->id,
                'from' => (string) ($link->message->author->display_name ?? 'Alguien'),
                'text' => (string) $link->message->text,
                'at' => $link->message->occurred_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function text(CommsNotification $notification, ?CommsIntent $intent): string
    {
        if ($intent !== null && filled($intent->what)) {
            return (string) $intent->what;
        }

        if ($notification->subject_type === 'question') {
            $text = CommsQuestion::query()->find($notification->subject_id)?->text;

            if (is_string($text) && $text !== '') {
                return $text;
            }
        }

        if ($notification->subject_type === 'proposal') {
            $text = CommsProposal::query()->find($notification->subject_id)?->payload['text'] ?? null;

            if (is_string($text) && $text !== '') {
                return $text;
            }
        }

        return match ($notification->kind) {
            'question' => 'Hay una pregunta abierta.',
            'proposal' => 'Hay una propuesta sin enviar.',
            'batch' => '¿Siguen abiertas?',
            default => 'Hay algo nuevo.',
        };
    }
}
