<?php

namespace App\Scenes\Kinds;

use App\Actions\Actions;
use App\Connectors\Mail\EmailComposer;
use App\Connectors\Mail\MailAddress;
use App\Connectors\Mail\Mailbox;
use App\Connectors\Mail\MailSender;
use App\Connectors\Mail\Outbox;
use App\Models\Scene;
use App\Scenes\SceneBoard;
use App\Scenes\SceneText;
use App\Scheduling\SlotFinder;
use App\Voice\Tools\Calendar\CalendarCanvas;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Find a free slot, then draft the reply with that time already in it.
 * No slot means no draft and no call to write one.
 */
class SlotThenReply implements SceneKind
{
    public function __construct(
        private SceneBoard $board,
        private SceneText $text,
        private SlotFinder $slots,
        private Mailbox $mailbox,
        private Outbox $outbox,
        private EmailComposer $composer,
        private Actions $actions,
        private CalendarCanvas $calendar,
    ) {}

    public function type(): string
    {
        return 'slot_then_reply';
    }

    public function title(): string
    {
        return 'Hueco y respuesta';
    }

    public function blank(array $input): array
    {
        return [
            SceneBoard::card('slot', 'Hueco'),
            SceneBoard::card('reply', 'Borrador'),
        ];
    }

    public function run(Scene $scene): void
    {
        $emailId = (string) ($scene->input['email_id'] ?? '');
        $minutes = (int) ($scene->input['minutes'] ?? 30);
        $from = CarbonImmutable::now($scene->input['timezone'] ?? 'Europe/Madrid');
        $until = $from->addDays(5);
        $found = $this->slots->find($scene->user, $from, $until, max(15, $minutes));

        if ($found === null) {
            $this->board->fill($scene, 'slot', [
                'state' => 'failed',
                'error' => 'No hay hueco en esos días.',
            ], error: 'No hay hueco en esos días.');
            $scene->refresh();
            $this->board->fill($scene, 'reply', [
                'state' => 'failed',
                'error' => 'Sin hueco no preparo la respuesta.',
            ], error: 'Sin hueco no preparo la respuesta.');

            $this->showCalendar($scene->refresh(), $from, $until);

            return;
        }

        [$start, $end] = $found;
        $when = $start->format('Y-m-d H:i').'–'.$end->format('H:i');
        $original = $this->mailbox->message($scene->user, $emailId);

        if ($original === null) {
            $this->board->fill($scene, 'slot', ['state' => 'ready', 'text' => $when]);
            $scene->refresh();
            $this->board->fill($scene, 'reply', [
                'state' => 'failed',
                'error' => 'No encuentro ese email.',
            ], error: 'No encuentro ese email.');

            return;
        }

        $draft = $this->text->capable(
            'Redactas la respuesta a un email, en primera persona, breve, en español. La hora libre ya está decidida: úsala. Devuelve json con la clave body.',
            "Hora libre: {$when}\n\nDe: {$original->from?->name}\nAsunto: {$original->subject}\n\n{$original->body}",
            [
                'type' => 'object',
                'properties' => ['body' => ['type' => 'string']],
                'required' => ['body'],
            ],
        );

        $body = trim((string) ($draft['body'] ?? ''));
        $sender = $this->outbox->senderFor($scene->user, $original->ref);

        if ($body === '' || $sender === null) {
            $this->board->fill($scene, 'slot', ['state' => 'ready', 'text' => $when]);
            $scene->refresh();
            $this->board->fill($scene, 'reply', [
                'state' => 'failed',
                'error' => $sender === null ? 'Esa cuenta no puede enviar.' : 'No he podido redactar la respuesta.',
            ], error: 'No he podido preparar la respuesta.');

            return;
        }

        $email = $this->composer->reply(
            $original,
            $sender,
            $body,
            false,
            [
                ...array_map(fn (MailSender $sender): string => $sender->email, $this->outbox->senders($scene->user)),
                ...$scene->user->connectedAccounts()->pluck('email')->all(),
            ],
        );

        $action = $this->actions->propose(
            $scene->user,
            'mail.send',
            'Responder a '.MailAddress::names($email->to),
            ['email' => $email->toArray()],
        );

        $this->board->fill($scene, 'slot', ['state' => 'ready', 'text' => $when]);
        $scene->refresh();
        $this->board->fill($scene, 'reply', [
            'state' => 'ready',
            'text' => $body,
            'action' => $action->toState(),
        ], $action->id);
    }

    /**
     * The days that had no room, beside the failed scene, so the person can decide what to move.
     */
    private function showCalendar(Scene $scene, CarbonImmutable $from, CarbonImmutable $until): void
    {
        try {
            $this->board->accompany($scene, $this->calendar->covering($scene->user, $from, $until, $from)->toArray());
        } catch (Throwable) {
            // The failed cards are already on screen.
        }
    }
}
