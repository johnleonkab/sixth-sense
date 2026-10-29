<?php

namespace App\Alerts;

use App\Alerts\Intents\AutonomyLevel;
use App\Alerts\Intents\IntentResearch;
use App\Alerts\Intents\SlackAction;
use App\Alerts\Intents\SlackAutonomy;
use App\Alerts\Intents\SlackDraft;
use App\Alerts\Intents\SlackDraftBrief;
use App\Alerts\Replies\AlertBrief;
use App\Alerts\Replies\ReplyPlan;
use App\Alerts\Replies\ReplyPlanner;
use App\Analytics\ProductAnalytics;
use App\Analytics\SlackTriageEvent;
use App\Connectors\Chat\ChatMessage;
use App\Connectors\Chat\Chats;
use App\Connectors\Issues\IssueComment;
use App\Connectors\Issues\IssueTracker;
use App\Connectors\Mail\MailAddress;
use App\Connectors\Mail\Mailbox;
use App\Events\InboxItemUpdated;
use App\Jobs\ScheduleTask;
use App\Models\AgendaTask;
use App\Models\InboxItem;
use App\Models\SlackTriageDecision;
use App\Models\User;
use App\Scenes\SceneMatch;
use Illuminate\Support\Str;
use Throwable;

/**
 * What happens to an alert after it is raised: the reply engine proposes how
 * to answer it, and once the person answers (here or in the service itself)
 * it is resolved.
 */
class AlertFollowUp
{
    /** Older pending alerts are no longer checked for an answer. */
    private const CHECK_DAYS = 14;

    public function __construct(
        private ReplyPlanner $planner,
        private Mailbox $mailbox,
        private Chats $chats,
        private IssueTracker $issues,
        private SceneMatch $scenes,
        private IntentResearch $research,
        private SlackDraft $drafts,
        private ProductAnalytics $analytics,
        private SlackAutonomy $autonomy,
    ) {}

    /**
     * Ask the reply engine how to resolve the alert, and keep its proposal.
     */
    public function plan(InboxItem $item): void
    {
        if ($item->status !== AlertStatus::Pending) {
            $this->stopPlanning($item);

            return;
        }

        $scene = $this->scenes->openFor($item);

        if ($scene !== null) {
            $item->update([
                'proposal' => [
                    'summary' => 'Hay una escena preparada en este aviso.',
                    'scene_id' => $scene->id,
                ],
                'planning' => false,
            ]);
            InboxItemUpdated::dispatch($item);

            return;
        }

        $brief = $this->brief($item);

        if ($brief === null) {
            $this->stopPlanning($item);

            return;
        }

        $decision = $this->slackDecision($item);
        $draft = null;

        if ($decision !== null) {
            $draft = $this->drafts->prepare($item->user, $brief, $decision);
            $brief = $brief->withFindings($draft->findings);
        } else {
            $brief = $brief->withFindings($this->research->gather($item->user, $brief));
        }

        $plan = $this->planner->plan($item->user, $brief);

        if (filled($plan->learned)) {
            $item->user->intentLessons()->create([
                'inbox_item_id' => $item->id,
                'body' => $plan->learned,
            ]);
        }

        $proposal = $plan->toArray();

        if ($draft !== null && $decision !== null) {
            $proposal = $this->proposal($plan, $decision, $draft);
        }

        $item->update(['proposal' => $proposal, 'planning' => false]);
        InboxItemUpdated::dispatch($item);

        if ($draft !== null && $decision !== null) {
            $this->analytics->capture($item->user, SlackTriageEvent::ReplyPlanned, [
                'action' => $decision->action,
                'place' => $decision->place,
                'sufficient' => $draft->sufficient,
                'agent_asked' => $draft->agentAsked,
                'conflict' => $draft->conflict,
            ]);

            $text = $this->draftText($proposal);

            if ($text !== null) {
                $decision->update(['draft_text' => $text]);
            }

            if ($text !== null && $draft->sufficient && $this->autonomy->level($item->user, SlackAction::from($decision->action)) === AutonomyLevel::Act) {
                $this->autonomy->perform($item->user, $decision, $text);
            }
        }

        // Work the person has to do themselves: the scheduler finds time for it.
        if ($decision?->action !== SlackAction::Redirect->value && $plan->task !== null && $item->user->wantsAlertsFrom(AlertSource::Agenda) && ! AgendaTask::where('inbox_item_id', $item->id)->exists()) {
            $task = $item->user->agendaTasks()->create([
                'inbox_item_id' => $item->id,
                'origin' => 'alert',
                'title' => $plan->task['title'],
                'context' => trim("{$item->actor_name}: {$item->why}\n\n".mb_substr($brief->text, 0, 1500)),
            ]);

            ScheduleTask::dispatch($task, $plan->task['effort_minutes'], $plan->task['deadline'], $plan->task['people']);
        }
    }

    /**
     * A Slack reply keeps the draft and adds what the search found. A redirect
     * proposes who and the message, and does not answer in the channel.
     *
     * @return array<string, mixed>
     */
    private function proposal(ReplyPlan $plan, SlackTriageDecision $decision, SlackDraftBrief $draft): array
    {
        if ($decision->action === SlackAction::Redirect->value) {
            $text = trim((string) ($plan->reply['text'] ?? ''));

            return [
                'summary' => 'Pasárselo a '.($decision->target ?: 'otra persona').'.',
                'reply' => null,
                'other_actions' => [],
                'question' => $draft->sufficient ? null : $draft->note,
                'task' => null,
                'redirect' => ['to' => $decision->target, 'text' => Str::limit(trim($text."\n\n".$draft->note), 2000)],
            ];
        }

        $proposal = $plan->toArray();

        if (is_array($proposal['reply'])) {
            $proposal['reply']['text'] = Str::limit(trim($proposal['reply']['text']."\n\n".$draft->note), 2000);
        }

        if (! $draft->sufficient) {
            $proposal['question'] = $draft->note;
        }

        return $proposal;
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function draftText(array $proposal): ?string
    {
        $redirect = $proposal['redirect']['text'] ?? null;
        $reply = $proposal['reply']['text'] ?? null;
        $text = trim((string) ($redirect ?: $reply));

        return $text === '' ? null : $text;
    }

    private function slackDecision(InboxItem $item): ?SlackTriageDecision
    {
        if ($item->source !== AlertSource::Slack) {
            return null;
        }

        $decision = $item->user->slackTriageDecisions()->where('external_id', $item->external_id)->first();

        if ($decision === null || ! in_array($decision->action, [SlackAction::Reply->value, SlackAction::Redirect->value], true)) {
            return null;
        }

        return $decision;
    }

    /**
     * The reply engine gave up (or had nothing to do): the alert stays, without a proposal.
     */
    public function stopPlanning(InboxItem $item): void
    {
        if ($item->planning) {
            $item->update(['planning' => false]);
            InboxItemUpdated::dispatch($item);
        }
    }

    /**
     * Resolve the user's pending alerts they already answered.
     *
     * @return list<InboxItem> Alerts resolved.
     */
    public function checkReplies(User $user): array
    {
        $resolved = [];

        $pending = $user->inboxItems()->pending()
            ->whereIn('source', [AlertSource::Gmail, AlertSource::Slack, AlertSource::Jira])
            ->where('received_at', '>=', now()->subDays(self::CHECK_DAYS))
            ->get();

        foreach ($pending as $item) {
            try {
                $replied = match ($item->source) {
                    AlertSource::Gmail => $this->mailbox->repliedAfter($user, $item->messageRef()),
                    AlertSource::Jira => $this->issues->repliedAfter($user, $item->messageRef()),
                    default => $this->chats->repliedAfter($user, $item->messageRef()),
                };
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            if ($replied) {
                $item->close(AlertStatus::Resolved, 'replied');
                InboxItemUpdated::dispatch($item);
                $resolved[] = $item;
            }
        }

        return $resolved;
    }

    /**
     * The message and the conversation around it, as the reply engine reads it.
     */
    public function brief(InboxItem $item): ?AlertBrief
    {
        try {
            return match ($item->source) {
                AlertSource::Gmail => $this->emailBrief($item),
                AlertSource::Jira => $this->issueBrief($item),
                AlertSource::Slack => $this->chatBrief($item),
                default => $this->keptBrief($item),
            };
        } catch (Throwable $exception) {
            // The service could not give it now: what the alert kept of the message is enough.
            report($exception);

            return $this->keptBrief($item);
        }
    }

    private function issueBrief(InboxItem $item): AlertBrief
    {
        $issue = $this->issues->issue($item->user, Str::beforeLast($item->messageRef(), ':'));
        $comment = collect($issue?->comments ?? [])->firstWhere('ref', $item->messageRef());

        if ($issue === null || $comment === null) {
            return $this->keptBrief($item);
        }

        $timezone = (string) config('app.timezone');

        return new AlertBrief(
            source: AlertSource::Jira,
            where: "{$issue->summary->key} · {$issue->summary->summary} ({$issue->summary->type}, {$issue->summary->status})",
            from: $comment->author,
            subject: $issue->summary->summary,
            text: $comment->text,
            receivedAt: $comment->createdAt,
            participants: array_values(array_unique(array_filter(array_map(fn (IssueComment $other): ?string => $other->mine ? null : $other->author, $issue->comments)))),
            conversation: [
                ['from' => 'Descripción del issue', 'when' => '', 'text' => Str::limit($issue->descriptionText, 1200)],
                ...array_map(fn (IssueComment $other): array => [
                    'from' => $other->mine ? "{$item->user->name} (él)" : $other->author,
                    'when' => $other->createdAt->setTimezone($timezone)->format('d/m H:i'),
                    'text' => $other->text,
                ], array_slice(array_filter($issue->comments, fn (IssueComment $other): bool => $other->ref !== $comment->ref), -8)),
            ],
            why: $item->why,
        );
    }

    private function keptBrief(InboxItem $item): AlertBrief
    {
        return new AlertBrief(
            source: $item->source,
            where: $item->source === AlertSource::Gmail ? 'Email' : $item->title,
            from: (string) $item->actor_name,
            subject: $item->source === AlertSource::Gmail ? $item->title : null,
            text: (string) $item->body,
            receivedAt: $item->received_at,
            why: $item->why,
        );
    }

    private function emailBrief(InboxItem $item): AlertBrief
    {
        $message = $this->mailbox->message($item->user, $item->messageRef());

        if ($message === null) {
            return $this->keptBrief($item);
        }

        $others = array_filter(
            [...$message->to, ...$message->cc],
            fn (MailAddress $address): bool => strcasecmp((string) $address->email, $message->account) !== 0,
        );

        return new AlertBrief(
            source: AlertSource::Gmail,
            where: 'Email',
            from: $message->from ? trim("{$message->from->name} <{$message->from->email}>") : 'Desconocido',
            subject: $message->subject,
            text: (string) ($message->body ?? $message->snippet),
            receivedAt: $message->date,
            participants: array_values(array_map(fn (MailAddress $address): string => $address->label(), $others)),
            why: $item->why,
        );
    }

    private function chatBrief(InboxItem $item): AlertBrief
    {
        $ref = $item->messageRef();
        $view = $this->chats->view($item->user, Str::beforeLast($ref, ':'), aroundRef: $ref, limit: 12);
        $message = collect($view?->messages ?? [])->firstWhere('ref', $ref);

        // Deleted, or out of reach now: what the alert kept of it is enough.
        if ($view === null || $message === null) {
            return $this->keptBrief($item);
        }

        $timezone = (string) config('app.timezone');

        return new AlertBrief(
            source: AlertSource::Slack,
            where: $item->title,
            from: $message->authorName,
            subject: null,
            text: $message->text,
            receivedAt: $message->sentAt,
            participants: array_values(array_unique(array_filter(array_map(
                fn (ChatMessage $other): ?string => $other->isMine || $other->authorName === $message->authorName ? null : $other->authorName,
                $view->messages,
            )))),
            conversation: array_values(array_map(fn (ChatMessage $other): array => [
                'from' => $other->isMine ? "{$item->user->name} (él)" : $other->authorName,
                'when' => $other->sentAt->setTimezone($timezone)->format('d/m H:i'),
                'text' => $other->text,
            ], array_filter($view->messages, fn (ChatMessage $other): bool => $other->ref !== $ref))),
            why: $item->why,
        );
    }
}
