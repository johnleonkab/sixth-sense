<?php

namespace App\Actions;

use App\Actions\Handlers\AcceptSlackAutonomy;
use App\Actions\Handlers\AcceptSlackPreference;
use App\Actions\Handlers\CallMcpTool;
use App\Actions\Handlers\ChangeCalendar;
use App\Actions\Handlers\ChangeJira;
use App\Actions\Handlers\ChangeSense;
use App\Actions\Handlers\ChangeWiki;
use App\Actions\Handlers\CommentDocument;
use App\Actions\Handlers\EditDocument;
use App\Actions\Handlers\ReactToChatMessage;
use App\Actions\Handlers\ReplyDocumentComment;
use App\Actions\Handlers\ScheduleAgendaTask;
use App\Actions\Handlers\SendChatMessage;
use App\Actions\Handlers\SendEmail;
use App\Alerts\Intents\SlackAutonomy;
use App\Alerts\Outcomes\ProposalOutcomes;
use App\Comms\Gate\ExecuteProposal;
use App\Jobs\ExecutePendingAction;
use App\Models\PendingAction;
use App\Models\User;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Writing to an external service always goes through here: the assistant
 * proposes, only the person confirms, and there is a window to undo.
 */
class Actions
{
    /** Seconds between "sí" and the change actually happening. */
    public const UNDO_WINDOW = 6;

    /**
     * How long an identical change that just ran still counts as the same one.
     * A second proposal in this window is the voice repeating the "sí".
     */
    public const DUPLICATE_WINDOW = 60;

    /** A proposal left unanswered for this long can no longer be confirmed. */
    public const PROPOSAL_LIFETIME = 600;

    /**
     * @var array<string, class-string<ActionHandler>>
     */
    private const HANDLERS = [
        'docs.edit' => EditDocument::class,
        'docs.comment' => CommentDocument::class,
        'docs.reply' => ReplyDocumentComment::class,
        'mail.send' => SendEmail::class,
        'calendar.change' => ChangeCalendar::class,
        'chat.send' => SendChatMessage::class,
        'chat.react' => ReactToChatMessage::class,
        'notion.create' => ChangeWiki::class,
        'notion.edit' => ChangeWiki::class,
        'notion.comment' => ChangeWiki::class,
        'jira.create' => ChangeJira::class,
        'jira.update' => ChangeJira::class,
        'jira.link' => ChangeJira::class,
        'jira.unlink' => ChangeJira::class,
        'jira.comment' => ChangeJira::class,
        'agenda.schedule' => ScheduleAgendaTask::class,
        'mcp.call' => CallMcpTool::class,
        'sense.write' => ChangeSense::class,
        'sense.delete' => ChangeSense::class,
        'slack.preference' => AcceptSlackPreference::class,
        'slack.autonomy' => AcceptSlackAutonomy::class,
        'comms.send_slack' => ExecuteProposal::class,
        'comms.send_email_reply' => ExecuteProposal::class,
        'comms.send_email_new' => ExecuteProposal::class,
        'comms.jira_comment' => ExecuteProposal::class,
        'comms.notion_comment' => ExecuteProposal::class,
        'comms.calendar_create' => ExecuteProposal::class,
        'comms.calendar_update' => ExecuteProposal::class,
        'comms.notion_create_page' => ExecuteProposal::class,
        'comms.drive_share' => ExecuteProposal::class,
        'comms.jira_update' => ExecuteProposal::class,
        'comms.notion_edit' => ExecuteProposal::class,
    ];

    public function __construct(private Container $container) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function propose(User $user, string $type, string $label, array $payload): PendingAction
    {
        if (! isset(self::HANDLERS[$type])) {
            throw new InvalidArgumentException("Unknown action type [{$type}].");
        }

        $existing = $this->openDuplicate($user, $type, $payload);

        if ($existing !== null) {
            return $existing;
        }

        return $user->pendingActions()->create([
            'type' => $type,
            'label' => $label,
            'payload' => $payload,
            'status' => ActionStatus::Pending,
            'expires_at' => now()->addSeconds(self::PROPOSAL_LIFETIME),
        ]);
    }

    /**
     * The same change already waiting, in its undo window, or just applied.
     * Key order and nulls do not make it a different change.
     */
    private function openDuplicate(User $user, string $type, array $payload): ?PendingAction
    {
        $fingerprint = $this->fingerprint($payload);

        return $user->pendingActions()
            ->where('type', $type)
            ->where(function ($query): void {
                $query->whereIn('status', [ActionStatus::Pending, ActionStatus::Confirmed, ActionStatus::Executing])
                    ->orWhere(fn ($query) => $query->where('status', ActionStatus::Executed)->where('executed_at', '>=', now()->subSeconds(self::DUPLICATE_WINDOW)));
            })
            ->latest('id')
            ->limit(20)
            ->get()
            ->first(fn (PendingAction $action): bool => $this->fingerprint($action->payload) === $fingerprint);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fingerprint(array $payload): string
    {
        return hash('sha256', (string) json_encode($this->canonical($payload)));
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $items = array_map($this->canonical(...), $value);
            usort($items, fn (mixed $left, mixed $right): int => strcmp((string) json_encode($left), (string) json_encode($right)));

            return $items;
        }

        $entries = [];

        foreach ($value as $key => $item) {
            if ($item !== null) {
                $entries[$key] = $this->canonical($item);
            }
        }

        ksort($entries);

        return $entries;
    }

    /**
     * The person said "sí": run it once the undo window closes.
     */
    public function confirm(PendingAction $action): PendingAction
    {
        if ($action->status === ActionStatus::Pending && $action->expires_at->isPast()) {
            $action->update(['status' => ActionStatus::Expired]);
        }

        if ($action->status !== ActionStatus::Pending) {
            return $action;
        }

        $action->update([
            'status' => ActionStatus::Confirmed,
            'undo_until' => now()->addSeconds(self::UNDO_WINDOW),
        ]);

        $this->container->make(SlackAutonomy::class)->note($action);
        $this->container->make(ProposalOutcomes::class)->confirmed($action);

        ExecutePendingAction::dispatch($action)->delay(now()->addSeconds(self::UNDO_WINDOW));

        return $action;
    }

    public function cancel(PendingAction $action): PendingAction
    {
        if ($action->status === ActionStatus::Pending) {
            $action->update(['status' => ActionStatus::Cancelled]);
        }

        return $action;
    }

    /**
     * Undo a confirmed action that has not run yet.
     */
    public function undo(PendingAction $action): PendingAction
    {
        if ($action->status === ActionStatus::Confirmed) {
            $action->update(['status' => ActionStatus::Undone]);
            $this->container->make(ProposalOutcomes::class)->undone($action);
        }

        return $action;
    }

    public function handlerFor(PendingAction $action): ActionHandler
    {
        return $this->container->make(self::HANDLERS[$action->type]);
    }
}
