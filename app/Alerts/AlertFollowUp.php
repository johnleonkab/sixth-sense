<?php

namespace App\Alerts;

use App\Alerts\Actions\ActionClassifier;
use App\Alerts\Actions\ClassifiedAction;
use App\Alerts\Intents\AutonomyLevel;
use App\Alerts\Intents\IntentResearch;
use App\Alerts\Intents\SlackAction;
use App\Alerts\Intents\SlackAutonomy;
use App\Alerts\Intents\SlackDraft;
use App\Alerts\Intents\SlackDraftBrief;
use App\Alerts\Outcomes\ProposalOutcomes;
use App\Alerts\Questions\QuestionAnswer;
use App\Alerts\Questions\QuestionResearch;
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
use App\Scheduling\AgendaTaskStatus;
use App\Scheduling\UsualDurations;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
        private ActionClassifier $classifier,
        private UsualDurations $durations,
        private QuestionResearch $questions,
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

        $actions = $decision?->action === SlackAction::Redirect->value ? [] : array_map(
            fn (ClassifiedAction $action): ClassifiedAction => $action->class === ClassifiedAction::CALL && $action->sure() ? $this->prepareCall($item, $action, $brief) : $action,
            $this->classify($item, $brief),
        );

        // A question someone asked: the answer is looked for before drafting.
        $question = collect($actions)->first(fn (ClassifiedAction $action): bool => $action->class === ClassifiedAction::QUESTION && $action->sure());
        $answer = $question !== null ? $this->questions->answer($item->user, $question, $brief) : null;

        if ($answer !== null) {
            $brief = $brief->withFindings(trim("{$brief->findings}\n\n{$answer->findings}"));
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

        $proposal['actions'] = array_map(fn (ClassifiedAction $action, int $index): array => $action->toArray("a{$index}"), $actions, array_keys($actions));

        if ($answer !== null) {
            $proposal = $this->withResearch($proposal, $answer);
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

            $text = $this->draftText($proposal, $decision);

            if ($text !== null) {
                $decision->update(['draft_text' => $text]);
            }

            // Passing something on as well takes two messages: the person sees both before they go.
            $relays = $decision->action === SlackAction::Reply->value && filled($proposal['redirect']['text'] ?? null);

            if ($text !== null && $draft->sufficient && ! $relays && $this->autonomy->level($item->user, SlackAction::from($decision->action)) === AutonomyLevel::Act) {
                $this->autonomy->perform($item->user, $decision, $text);
            }
        }

        // A call or work of the person's own, when the classifier is sure: the scheduler finds time for it.
        // A doubt waits for the person to settle it (AlertActions::settle).
        foreach ($actions as $action) {
            if ($action->sure()) {
                $this->schedule($item, $action, $brief);
            }
        }
    }

    /**
     * What the message asks of the person. A classifier that fails leaves the
     * proposal as it was: just the reply.
     *
     * @return list<ClassifiedAction>
     */
    private function classify(InboxItem $item, AlertBrief $brief): array
    {
        try {
            return $this->classifier->classify($item->user, $brief);
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * What a call still needs before looking for a slot: who (when the
     * classifier is not sure, the people in the conversation are ticked and
     * the person's usual colleagues suggested) and how long (15 to 60 minutes
     * inferred are used as they are; else the usual length with those people,
     * once it has been the same three times; else it asks).
     */
    public function prepareCall(InboxItem $item, ClassifiedAction $action, AlertBrief $brief): ClassifiedAction
    {
        $data = $action->data;
        $people = $data['people'] ?? [];
        $minutes = $data['duration_minutes'] ?? null;
        $needs = [];

        if (! ($data['people_sure'] ?? false) || $people === []) {
            $needs[] = 'people';
            $asked = array_values(array_unique(array_filter(array_map(fn (string $name): string => trim(Str::before($name, '<')), [$brief->from, ...$brief->participants, ...$people]))));
            $asked = array_values(array_diff($asked, [$item->user->name]));
            $suggested = collect($this->chats->familiar($item->user, 8)['people'])
                ->map(fn ($person): string => $person->name)
                ->reject(fn (string $name): bool => $name === $item->user->name || in_array($name, $asked, true))
                ->take(5);
            $data['candidates'] = [
                ...array_map(fn (string $name): array => ['name' => $name, 'checked' => true], $asked),
                ...$suggested->map(fn (string $name): array => ['name' => $name, 'checked' => false])->values()->all(),
            ];
            $data['people'] = $asked;
        }

        if ($minutes === null || $minutes < 15 || $minutes > 60) {
            $usual = $this->durations->forCall($item->user, $data['people']);

            if ($usual !== null) {
                $data['duration_minutes'] = $usual;
            } else {
                unset($data['duration_minutes']);
                $needs[] = 'duration';
            }
        }

        if ($needs !== []) {
            $data['needs'] = $needs;
        }

        return new ClassifiedAction($action->class, $action->confidence, $action->summary, $action->doubt, $data);
    }

    /**
     * Sends a call or a task to the scheduler, once per alert, when nothing is
     * missing for it.
     */
    public function schedule(InboxItem $item, ClassifiedAction $action, ?AlertBrief $brief = null): void
    {
        if (! in_array($action->class, [ClassifiedAction::CALL, ClassifiedAction::TASK], true)
            || ($action->data['needs'] ?? []) !== []
            || ! $item->user->wantsAlertsFrom(AlertSource::Agenda)
            || AgendaTask::where('inbox_item_id', $item->id)->where('status', '!=', AgendaTaskStatus::Cancelled)->exists()) {
            return;
        }

        $call = $action->class === ClassifiedAction::CALL;
        $people = $call ? ($action->data['people'] ?? []) : [];
        $task = $item->user->agendaTasks()->create([
            'inbox_item_id' => $item->id,
            'origin' => 'alert',
            'title' => Str::limit($call ? 'Llamada con '.($people === [] ? $item->actor_name : implode(', ', $people)) : $action->summary, 120),
            'context' => trim("{$item->actor_name}: {$item->why}\n".(filled($action->data['deliverable'] ?? null) ? "Entregable: {$action->data['deliverable']}\n" : '')."\n".mb_substr($brief?->text ?? (string) $item->body, 0, 1500)),
        ]);

        ScheduleTask::dispatch($task, $action->data['duration_minutes'] ?? null, $action->data['deadline'] ?? null, $people, $action->data['urgency'] ?? null, $call);
    }

    /**
     * Once the scheduler has looked for the call, the reply engine writes the
     * answer with what it found: the slot, a few to choose from when someone's
     * calendar can not be seen, or a question about when they are flexible.
     */
    public function answerCall(AgendaTask $task): void
    {
        $item = $task->inboxItem;
        $brief = $item?->status === AlertStatus::Pending ? $this->brief($item) : null;

        if ($brief === null) {
            return;
        }

        $timezone = config('agenda.timezone');
        $when = fn (CarbonInterface|string $at): string => CarbonImmutable::parse($at)->setTimezone($timezone)->locale('es')->isoFormat('dddd D [de] MMMM, HH:mm');
        $names = implode(', ', array_filter(array_map(fn (array $person): ?string => $person['name'] ?? $person['email'] ?? null, $task->attendees ?? []))) ?: $item->actor_name;
        $note = match ($task->status) {
            AgendaTaskStatus::Proposed => "Llamada preparada: el {$when($task->starts_at)} ({$task->duration_minutes} min) con {$names}. La invitación sale con el mismo «sí». "
                .'La respuesta le propone ese hueco, en una o dos frases, y le dice que le manda la invitación.',
            AgendaTaskStatus::Offered => 'En la llamada hay alguien de fuera cuya agenda no se ve. La respuesta le propone estos huecos para que elija uno, sin invitación todavía: '
                .implode('; ', array_map(fn (array $option): string => $when($option[0]), $task->options ?? [])).'.',
            AgendaTaskStatus::Conflict => 'No hay hueco para la llamada en los próximos días. La respuesta lo dice con naturalidad y le pregunta qué días o franjas tiene más flexibles.',
            default => null,
        };

        if ($note === null) {
            return;
        }

        try {
            $plan = $this->planner->plan($item->user, $brief->withFindings(trim("{$brief->findings}\n\n{$note}")));
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        $item->update(['proposal' => [
            ...($item->proposal ?? []),
            'summary' => $plan->summary,
            'reply' => $plan->reply,
            'reaction' => null,
            'question' => $plan->question,
        ]]);
        InboxItemUpdated::dispatch($item);
    }

    /**
     * The person picked which of the candidates answers the question: the
     * reply is drafted again with it.
     */
    public function answerQuestion(InboxItem $item, int $choice): bool
    {
        $source = $item->proposal['research']['sources'][$choice] ?? null;
        $brief = $source !== null && ($item->proposal['research']['outcome'] ?? null) === QuestionAnswer::SEVERAL ? $this->brief($item) : null;

        if ($brief === null) {
            return false;
        }

        $answer = $this->questions->from($item->user, $source);
        $plan = $this->planner->plan($item->user, $brief->withFindings(trim("{$brief->findings}\n\n{$answer->findings}")));

        $item->update(['proposal' => $this->withResearch([
            ...($item->proposal ?? []),
            'summary' => $plan->summary,
            'reply' => $plan->reply,
            'reaction' => null,
            'question' => $plan->question,
        ], $answer)]);
        InboxItemUpdated::dispatch($item);

        return true;
    }

    /**
     * What the search found goes with the proposal. Several candidates: no
     * draft until the person says which. Nothing found: it says so.
     *
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    private function withResearch(array $proposal, QuestionAnswer $answer): array
    {
        $proposal['research'] = $answer->toArray();

        return match ($answer->outcome) {
            QuestionAnswer::SEVERAL => [...$proposal, 'reply' => null, 'reaction' => null, 'question' => '¿Cuál de estos responde a lo que pregunta?'],
            QuestionAnswer::NONE => [...$proposal, 'question' => $proposal['question'] ?? 'No he encontrado la respuesta: dime qué le contesto.'],
            default => $proposal,
        };
    }

    /**
     * A Slack reply keeps the draft, and what the search found goes beside it
     * as context: it is for the person, never part of what is sent. A redirect
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
                'redirect' => ['to' => $decision->target, 'text' => Str::limit($text, 2000)],
                'context' => $draft->note,
            ];
        }

        $proposal = [...$plan->toArray(), 'context' => $draft->note];

        if (! $draft->sufficient) {
            $proposal['question'] = $draft->note;
        }

        return $proposal;
    }

    /**
     * The text the decision would send: the redirect's, or the reply's.
     *
     * @param  array<string, mixed>  $proposal
     */
    private function draftText(array $proposal, SlackTriageDecision $decision): ?string
    {
        $text = trim((string) ($decision->action === SlackAction::Redirect->value ? ($proposal['redirect']['text'] ?? '') : ($proposal['reply']['text'] ?? '')));

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
                app(ProposalOutcomes::class)->resolved($item, 'replied_outside');
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
