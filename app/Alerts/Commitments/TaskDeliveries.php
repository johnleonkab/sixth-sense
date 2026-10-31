<?php

namespace App\Alerts\Commitments;

use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Alerts\Triage;
use App\Connectors\Chat\ChatMessage;
use App\Connectors\Chat\Chats;
use App\Connectors\Mail\Mailbox;
use App\Connectors\Mail\MailSearch;
use App\Events\InboxItemCreated;
use App\Models\AgendaTask;
use App\Models\InboxItem;
use App\Models\User;
use App\Scheduling\AgendaTaskStatus;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The day before a task from a message is due: if nothing shows it was
 * delivered (the person writing back to whoever expects it), one alert asks
 * whether it is on its way. Only once per task.
 */
class TaskDeliveries
{
    public function __construct(private Chats $chats, private Mailbox $mailbox) {}

    /**
     * @return list<InboxItem> The follow-ups raised.
     */
    public function check(User $user): array
    {
        $tomorrow = CarbonImmutable::now(config('agenda.timezone'))->addDay()->toDateString();
        $raised = [];

        $due = $user->agendaTasks()
            ->where('origin', 'alert')
            ->where('kind', 'task')
            ->where('status', AgendaTaskStatus::Scheduled)
            ->whereDate('deadline', $tomorrow)
            ->with('inboxItem')
            ->get();

        foreach ($due as $task) {
            if ($task->inboxItem === null || $user->inboxItems()->where('source', AlertSource::Agenda)->where('external_id', "due:{$task->id}")->exists() || $this->delivered($user, $task)) {
                continue;
            }

            $person = $task->inboxItem->actor_name;
            $item = $user->inboxItems()->create([
                'source' => AlertSource::Agenda,
                'external_id' => "due:{$task->id}",
                'external_url' => $task->inboxItem->external_url,
                'actor_name' => $person,
                'title' => "Mañana vence: {$task->title}",
                'body' => $task->context,
                'why' => "Mañana vence «{$task->title}» para {$person} y no veo que se lo hayas pasado. ¿Vas bien?",
                'relevance' => 75,
                'status' => AlertStatus::Pending,
                'triage' => Triage::Important,
                'payload' => ['agenda_task_id' => $task->id, 'follow_up' => true],
                'received_at' => now(),
            ]);

            InboxItemCreated::dispatch($item);
            $raised[] = $item;
        }

        return $raised;
    }

    /**
     * A sign that it went out: the person wrote back where it was asked, or,
     * for their own promise, wrote again to the same person, after the task
     * was booked.
     */
    private function delivered(User $user, AgendaTask $task): bool
    {
        $alert = $task->inboxItem;
        $promise = $alert->payload['commitment'] ?? null;
        $since = CarbonImmutable::parse($task->created_at);

        try {
            if ($promise === null) {
                return match ($alert->source) {
                    AlertSource::Gmail => $this->mailbox->repliedAfter($user, $alert->messageRef()),
                    AlertSource::Slack => $this->chats->repliedAfter($user, $alert->messageRef()),
                    default => false,
                };
            }

            if ($promise['source'] === AlertSource::Slack->value) {
                return collect($this->chats->activity($user, $since, 150))
                    ->contains(fn (ChatMessage $message): bool => $message->isMine && $message->sentAt->greaterThan($since) && $message->conversationLabel === $promise['to']);
            }

            return collect($this->mailbox->search($user, new MailSearch(to: $promise['to'], after: $since->startOfDay(), folder: 'sent', limit: 5))->messages)
                ->contains(fn ($found): bool => $found->message->date->greaterThan($since));
        } catch (Throwable $exception) {
            report($exception);

            // Unknown is not "not delivered": better no follow-up than a wrong one.
            return true;
        }
    }
}
