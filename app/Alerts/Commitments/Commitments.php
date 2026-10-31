<?php

namespace App\Alerts\Commitments;

use App\Alerts\Actions\ActionClassifier;
use App\Alerts\Actions\ClassifiedAction;
use App\Alerts\AlertFollowUp;
use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Alerts\Replies\AlertBrief;
use App\Alerts\Triage;
use App\Connectors\Chat\ChatMessage;
use App\Connectors\Chat\Chats;
use App\Connectors\Mail\FoundMessage;
use App\Connectors\Mail\MailAddress;
use App\Connectors\Mail\Mailbox;
use App\Connectors\Mail\MailSearch;
use App\Events\InboxItemCreated;
use App\Models\InboxItem;
use App\Models\SeenMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * What the person promised in their own messages, in Slack and in sent email:
 * "te lo paso antes del jueves". The classifier decides whether it is a task
 * (something delivered, to someone, by a date); when it is, an alert offers to
 * book time for it, and nothing reaches the calendar before the "sí". Only
 * for people who let the scheduler raise alerts.
 */
class Commitments
{
    /** Words of a promise; the classifier decides the rest, so this only saves it from every message. */
    private const PROMISE = '/\b(te lo (paso|mando|env[ií]o|hago|preparo|reviso|tengo)|te (paso|mando|env[ií]o)|os (paso|mando|env[ií]o)|me encargo|lo (preparo|reviso|tengo listo)|i\'?ll (send|share|do|prepare)|will (send|share))\b/iu';

    /** And of a moment: without one there is no deadline, so no task. */
    private const WHEN = '/\b(hoy|mañana|lunes|martes|mi[eé]rcoles|jueves|viernes|semana|antes de|para el|fin de|eod|today|tomorrow|monday|tuesday|wednesday|thursday|friday|week|\d{1,2}\/\d{1,2})\b/iu';

    private const LOOK_BACK_MINUTES = 30;

    public function __construct(
        private Chats $chats,
        private Mailbox $mailbox,
        private ActionClassifier $classifier,
        private AlertFollowUp $followUp,
    ) {}

    /**
     * @return list<InboxItem> The alerts opened for new commitments.
     */
    public function check(User $user): array
    {
        if (! $user->wantsAlertsFrom(AlertSource::Agenda)) {
            return [];
        }

        $since = CarbonImmutable::parse(Cache::get($this->cursorKey($user), now()->subMinutes(self::LOOK_BACK_MINUTES)->toIso8601String()));
        $now = CarbonImmutable::now();
        $opened = [];

        foreach ($this->said($user, $since) as $said) {
            $item = $this->consider($user, ...$said);

            if ($item !== null) {
                $opened[] = $item;
            }
        }

        Cache::put($this->cursorKey($user), $now->toIso8601String(), now()->addDays(2));

        return $opened;
    }

    /**
     * What the person wrote since then that sounds like a promise.
     *
     * @return list<array{AlertSource, string, ?string, string, string, ?string, string, CarbonImmutable}> source, ref, url, where, to, subject, text, when
     */
    private function said(User $user, CarbonImmutable $since): array
    {
        $said = [];

        try {
            foreach ($this->chats->activity($user, $since, 100) as $message) {
                /** @var ChatMessage $message */
                if ($message->isMine && $message->sentAt->greaterThanOrEqualTo($since) && $this->sounds($message->text)) {
                    $said[] = [AlertSource::Slack, $message->ref, $message->url, $message->conversationLabel, $message->conversationLabel, null, $message->text, $message->sentAt];
                }
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        try {
            foreach ($this->mailbox->search($user, new MailSearch(after: $since->startOfDay(), folder: 'sent', limit: 20))->messages as $found) {
                /** @var FoundMessage $found */
                $mail = $found->message;
                $text = $mail->body ?? $mail->snippet;

                if ($mail->date->greaterThanOrEqualTo($since) && $this->sounds($text)) {
                    $to = implode(', ', array_map(fn (MailAddress $address): string => $address->name ?: (string) $address->email, $mail->to));
                    $said[] = [AlertSource::Gmail, $mail->ref, $mail->url, 'Email enviado', $to, $mail->subject, $text, $mail->date];
                }
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        return $said;
    }

    private function sounds(string $text): bool
    {
        return preg_match(self::PROMISE, $text) === 1 && preg_match(self::WHEN, $text) === 1;
    }

    private function consider(User $user, AlertSource $source, string $ref, ?string $url, string $where, string $to, ?string $subject, string $text, CarbonImmutable $when): ?InboxItem
    {
        $seen = SeenMessage::query()->firstOrCreate(['user_id' => $user->id, 'source' => $source, 'external_id' => "commitment:{$ref}"]);

        if (! $seen->wasRecentlyCreated) {
            return null;
        }

        $brief = new AlertBrief($source, $where, $user->name, $subject, "Lo escribe {$user->name} a {$to}:\n{$text}", $when, why: 'Mensaje enviado por la propia persona: busca si se compromete a entregar algo con fecha.');
        $task = collect($this->classifier->classify($user, $brief))
            ->first(fn (ClassifiedAction $action): bool => $action->class === ClassifiedAction::TASK && $action->sure() && ($action->data['origin'] ?? null) === 'committed');

        if ($task === null) {
            return null;
        }

        $person = $task->data['expected_by'] ?? $to;
        // The scheduler's own alert: the message is the person's, so there is nothing to answer.
        $item = $user->inboxItems()->create([
            'source' => AlertSource::Agenda,
            'external_id' => "commitment:{$ref}",
            'external_url' => $url,
            'actor_name' => Str::limit($person, 120),
            'title' => Str::limit($task->summary, 200),
            'body' => Str::limit($text, 4000),
            'why' => Str::limit("Le dijiste a {$person}: «".Str::limit(trim($text), 140).'». ¿Lo pongo en el calendario?', 400),
            'relevance' => 70,
            'status' => AlertStatus::Pending,
            'triage' => Triage::Important,
            'payload' => ['commitment' => ['source' => $source->value, 'ref' => $ref, 'to' => $to]],
            'proposal' => [
                'summary' => "Reservar tiempo para «{$task->summary}».",
                'reply' => null,
                'other_actions' => [],
                'question' => null,
                'actions' => [$task->toArray('a0')],
            ],
            'received_at' => $when,
        ]);

        InboxItemCreated::dispatch($item);
        $this->followUp->schedule($item, $task, $brief);

        return $item->refresh();
    }

    private function cursorKey(User $user): string
    {
        return "commitments:{$user->id}:since";
    }
}
