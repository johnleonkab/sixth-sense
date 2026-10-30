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
    public const UNDO_WINDOW = 8;

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

        return $user->pendingActions()->create([
            'type' => $type,
            'label' => $label,
            'payload' => $payload,
            'status' => ActionStatus::Pending,
            'expires_at' => now()->addSeconds(self::PROPOSAL_LIFETIME),
        ]);
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
        }

        return $action;
    }

    public function handlerFor(PendingAction $action): ActionHandler
    {
        return $this->container->make(self::HANDLERS[$action->type]);
    }
}
