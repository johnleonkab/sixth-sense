<?php

namespace App\Alerts\Outcomes;

use App\Alerts\AlertSource;
use App\Connectors\Calendar\CalendarReader;
use App\Connectors\Calendar\EventQuery;
use App\Connectors\Chat\ChatMessage;
use App\Connectors\Chat\Chats;
use App\Connectors\Issues\IssueTracker;
use App\Connectors\Mail\Mailbox;
use App\Knowledge\Gaps\GapKind;
use App\Knowledge\Gaps\GapStatus;
use App\Models\InboxItem;
use App\Models\ProposalOutcome;
use App\Scheduling\AgendaTaskStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * An alert the person marked as resolved: how was it resolved? First from what
 * can be seen (they answered, someone else answered in the thread, they met
 * that person); only when nothing says, a question — at most one a week.
 */
class ResolutionInference
{
    /** Days between two questions about how something was resolved. */
    private const QUESTION_EVERY_DAYS = 7;

    public function __construct(
        private Chats $chats,
        private Mailbox $mailbox,
        private IssueTracker $issues,
        private CalendarReader $calendar,
    ) {}

    /**
     * @return ?string How it was resolved, or null when it could not be seen.
     */
    public function infer(ProposalOutcome $outcome): ?string
    {
        $alert = $outcome->inboxItem;

        if ($alert === null) {
            return null;
        }

        $how = $this->seen($alert);

        if ($how !== null) {
            $outcome->update(['detail' => [...($outcome->detail ?? []), 'how' => $how]]);

            return $how;
        }

        $this->ask($alert);

        return null;
    }

    private function seen(InboxItem $alert): ?string
    {
        try {
            return match ($alert->source) {
                AlertSource::Slack => $this->chats->repliedAfter($alert->user, $alert->messageRef()) ? 'replied' : ($this->someoneElseAnswered($alert) ? 'someone_else' : $this->met($alert)),
                AlertSource::Gmail => $this->mailbox->repliedAfter($alert->user, $alert->messageRef()) ? 'replied' : $this->met($alert),
                AlertSource::Jira => $this->issues->repliedAfter($alert->user, $alert->messageRef()) ? 'replied' : $this->met($alert),
                default => $this->met($alert),
            };
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * Whether a pending alert stopped making sense on its own: someone else
     * answered it in its thread (without asking anything back of the person),
     * or the call it asked for already happened.
     *
     * @return ?string 'someone_else' or 'call_happened', or null while it still stands.
     */
    public function expiry(InboxItem $alert): ?string
    {
        try {
            if ($alert->source === AlertSource::Slack && $this->someoneElseSettled($alert)) {
                return 'someone_else';
            }

            $asksCall = collect($alert->proposal['actions'] ?? [])->contains(fn (array $action): bool => ($action['class'] ?? null) === 'call' && ($action['confidence'] ?? null) === 'sure');
            $task = $alert->agendaTask;

            if ($asksCall && (($task?->status === AgendaTaskStatus::Scheduled && $task->ends_at?->isPast()) || $this->met($alert) !== null)) {
                return 'call_happened';
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        return null;
    }

    /**
     * Someone else answered in the thread after the alert, and did not hand
     * it back to the person (no question, no mention of them).
     */
    private function someoneElseSettled(InboxItem $alert): bool
    {
        $view = $this->chats->view($alert->user, Str::beforeLast($alert->messageRef(), ':'), $alert->messageRef(), $alert->thread_key);

        return collect($view?->messages ?? [])->contains(fn (ChatMessage $message): bool => ! $message->isMine
            && $message->authorName !== $alert->actor_name
            && $message->sentAt->greaterThan($alert->received_at)
            && ! $message->mentionsMe
            && ! str_ends_with(rtrim($message->text), '?'));
    }

    /**
     * Someone other than the person and whoever asked wrote in the thread afterwards.
     */
    private function someoneElseAnswered(InboxItem $alert): bool
    {
        $view = $this->chats->view($alert->user, Str::beforeLast($alert->messageRef(), ':'), $alert->messageRef(), $alert->thread_key);

        return collect($view?->messages ?? [])->contains(fn (ChatMessage $message): bool => ! $message->isMine
            && $message->authorName !== $alert->actor_name
            && $message->sentAt->greaterThan($alert->received_at));
    }

    /**
     * A meeting with whoever asked between the alert and now.
     */
    private function met(InboxItem $alert): ?string
    {
        $who = trim((string) Str::before((string) $alert->actor_name, ' '));

        if ($who === '') {
            return null;
        }

        $timezone = (string) config('agenda.timezone');
        $query = new EventQuery(CarbonImmutable::parse($alert->received_at)->setTimezone($timezone), CarbonImmutable::now($timezone), attendee: $who);
        $met = collect($this->calendar->read($alert->user, $query)->events)->contains(fn ($event): bool => ! $event->declined && ! $event->allDay && $query->matches($event));

        return $met ? 'met' : null;
    }

    private function ask(InboxItem $alert): void
    {
        $user = $alert->user;
        $recent = $user->knowledgeGaps()->where('kind', GapKind::Resolution)->where('created_at', '>=', now()->subDays(self::QUESTION_EVERY_DAYS))->exists();

        if ($recent) {
            return;
        }

        $user->knowledgeGaps()->firstOrCreate(['kind' => GapKind::Resolution, 'subject_key' => "alert:{$alert->id}"], [
            'subject' => Str::limit(trim(($alert->actor_name ? "{$alert->actor_name}: " : '').$alert->title), 120),
            'question' => "Diste por resuelto el aviso de {$alert->actor_name} («{$alert->title}») y no veo cómo. ¿Cómo se resolvió?",
            'context' => ['alert_id' => $alert->id],
            'status' => GapStatus::Open,
            'priority' => 0,
        ]);
    }
}
