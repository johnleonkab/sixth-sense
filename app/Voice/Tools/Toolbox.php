<?php

namespace App\Voice\Tools;

use App\Agents\References\IssuedRefs;
use App\Models\User;
use App\Voice\Tools\Actions\GetActionStatus;
use App\Voice\Tools\Agenda\CancelScheduledTask;
use App\Voice\Tools\Agenda\ScheduleTaskTool;
use App\Voice\Tools\Agents\ComposeView;
use App\Voice\Tools\Agents\Delegate;
use App\Voice\Tools\Agents\Present;
use App\Voice\Tools\Alerts\ListAlerts;
use App\Voice\Tools\Alerts\ResolveAlert;
use App\Voice\Tools\Alerts\ReviewAlert;
use App\Voice\Tools\Alerts\ShowAlertHistory;
use App\Voice\Tools\Analytics\ShowAnalyticsReport;
use App\Voice\Tools\Calendar\FindCalendarEvents;
use App\Voice\Tools\Calendar\ProposeEventCancellation;
use App\Voice\Tools\Calendar\ProposeEventInvite;
use App\Voice\Tools\Calendar\ProposeEventResponse;
use App\Voice\Tools\Calendar\ProposeEventSummary;
use App\Voice\Tools\Calendar\ProposeEventUpdate;
use App\Voice\Tools\Calendar\ProposeNewEvent;
use App\Voice\Tools\Calendar\ShowCalendar;
use App\Voice\Tools\Docs\FindFiles;
use App\Voice\Tools\Docs\OpenDocument;
use App\Voice\Tools\Docs\ProposeCommentReply;
use App\Voice\Tools\Docs\ProposeDocumentComment;
use App\Voice\Tools\Docs\ProposeDocumentEdit;
use App\Voice\Tools\Docs\SearchDocuments;
use App\Voice\Tools\Docs\ShowDocumentResults;
use App\Voice\Tools\Jira\OpenJiraIssue;
use App\Voice\Tools\Jira\ProposeJiraComment;
use App\Voice\Tools\Jira\ProposeJiraDependency;
use App\Voice\Tools\Jira\ProposeJiraIssue;
use App\Voice\Tools\Jira\ProposeJiraUpdate;
use App\Voice\Tools\Jira\SearchJira;
use App\Voice\Tools\Knowledge\AnswerQuestion;
use App\Voice\Tools\Knowledge\ReadKnowledge;
use App\Voice\Tools\Knowledge\SearchKnowledge;
use App\Voice\Tools\Mail\OpenEmail;
use App\Voice\Tools\Mail\OpenEmailAttachment;
use App\Voice\Tools\Mail\ProposeEmailForward;
use App\Voice\Tools\Mail\ProposeEmailReply;
use App\Voice\Tools\Mail\ProposeNewEmail;
use App\Voice\Tools\Mail\SearchEmails;
use App\Voice\Tools\Mail\ShowEmailResults;
use App\Voice\Tools\Mcp\CallMcpTool;
use App\Voice\Tools\Mcp\ListMcpTools;
use App\Voice\Tools\Notion\OpenNotionPage;
use App\Voice\Tools\Notion\ProposeNotionComment;
use App\Voice\Tools\Notion\ProposeNotionEdit;
use App\Voice\Tools\Notion\ProposeNotionPage;
use App\Voice\Tools\Notion\SearchNotion;
use App\Voice\Tools\Scenes\OpenSceneTool;
use App\Voice\Tools\Screen\ControlViewer;
use App\Voice\Tools\Sense\FocusSenseAgent;
use App\Voice\Tools\Sense\ListSenseFiles;
use App\Voice\Tools\Sense\ProposeSenseDelete;
use App\Voice\Tools\Sense\ProposeSenseFile;
use App\Voice\Tools\Sense\ReadSenseFile;
use App\Voice\Tools\Sense\SyncSenseAgents;
use App\Voice\Tools\Sense\UseSenseSkill;
use App\Voice\Tools\Slack\AnswerSlackAttention;
use App\Voice\Tools\Slack\CorrectSlackTriage;
use App\Voice\Tools\Slack\FindSlackConversations;
use App\Voice\Tools\Slack\FindSlackPeople;
use App\Voice\Tools\Slack\LowerSlackAutonomy;
use App\Voice\Tools\Slack\OpenSlackFile;
use App\Voice\Tools\Slack\ProposeSlackAutonomy;
use App\Voice\Tools\Slack\ProposeSlackMessage;
use App\Voice\Tools\Slack\ProposeSlackReaction;
use App\Voice\Tools\Slack\SearchSlackMessages;
use App\Voice\Tools\Slack\ShowSlackAutonomy;
use App\Voice\Tools\Slack\ShowSlackConversation;
use App\Voice\Tools\Slack\ShowSlackDigest;
use App\Voice\Tools\Slack\ShowSlackUnread;
use App\Voice\Tools\Views\ForgetView;
use App\Voice\Tools\Views\OpenSavedView;
use App\Voice\Tools\Views\SaveView;
use App\Voice\Tools\Views\ShowView;
use App\Voice\Tools\Views\ViewDataGuide;
use App\Voice\Tools\Wingman\DismissMeetingActions;
use App\Voice\Tools\Wingman\ProposeMeetingAction;
use App\Voice\Tools\Wingman\ReviewMeeting;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Validator;

/**
 * Every tool the voice assistant can call.
 */
class Toolbox
{
    /**
     * @var list<class-string<VoiceTool>>
     */
    private const TOOLS = [
        FindCalendarEvents::class,
        ShowCalendar::class,
        ProposeNewEvent::class,
        ProposeEventInvite::class,
        ProposeEventSummary::class,
        ProposeEventResponse::class,
        ProposeEventUpdate::class,
        ProposeEventCancellation::class,
        ScheduleTaskTool::class,
        CancelScheduledTask::class,
        SearchEmails::class,
        ShowEmailResults::class,
        OpenEmail::class,
        OpenEmailAttachment::class,
        ProposeNewEmail::class,
        ProposeEmailReply::class,
        ProposeEmailForward::class,
        SearchDocuments::class,
        ShowDocumentResults::class,
        FindFiles::class,
        OpenDocument::class,
        ProposeDocumentEdit::class,
        ProposeDocumentComment::class,
        ProposeCommentReply::class,
        ShowSlackUnread::class,
        ShowSlackDigest::class,
        CorrectSlackTriage::class,
        AnswerSlackAttention::class,
        ShowSlackAutonomy::class,
        ProposeSlackAutonomy::class,
        LowerSlackAutonomy::class,
        ShowSlackConversation::class,
        SearchSlackMessages::class,
        FindSlackConversations::class,
        FindSlackPeople::class,
        OpenSlackFile::class,
        SearchJira::class,
        OpenJiraIssue::class,
        ProposeJiraIssue::class,
        ProposeJiraUpdate::class,
        ProposeJiraDependency::class,
        ProposeJiraComment::class,
        SearchNotion::class,
        OpenNotionPage::class,
        ProposeNotionPage::class,
        ProposeNotionEdit::class,
        ProposeNotionComment::class,
        ProposeSlackMessage::class,
        ProposeSlackReaction::class,
        ControlViewer::class,
        GetActionStatus::class,
        ListAlerts::class,
        ShowAlertHistory::class,
        ReviewAlert::class,
        ResolveAlert::class,
        ReviewMeeting::class,
        ProposeMeetingAction::class,
        DismissMeetingActions::class,
        SearchKnowledge::class,
        ReadKnowledge::class,
        AnswerQuestion::class,
        ShowView::class,
        ViewDataGuide::class,
        SaveView::class,
        OpenSavedView::class,
        ForgetView::class,
        ListMcpTools::class,
        CallMcpTool::class,
        ShowAnalyticsReport::class,
        ListSenseFiles::class,
        ReadSenseFile::class,
        ProposeSenseFile::class,
        ProposeSenseDelete::class,
        SyncSenseAgents::class,
        FocusSenseAgent::class,
        UseSenseSkill::class,
        OpenSceneTool::class,
        Delegate::class,
        Present::class,
        ComposeView::class,
    ];

    public function __construct(private Container $container, private IssuedRefs $refs) {}

    /**
     * @return list<VoiceTool>
     */
    public function all(): array
    {
        return array_map(fn (string $tool): VoiceTool => $this->container->make($tool), self::TOOLS);
    }

    public function find(string $name): ?VoiceTool
    {
        foreach ($this->all() as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * Provider-neutral declarations for the voice session setup: only what
     * the voice may call itself (config/agents.php, voice_tools). The rest is
     * the agents'.
     *
     * @return list<array{name: string, description: string, parameters: array<string, mixed>}>
     */
    public function declarations(): array
    {
        return array_values(array_map(fn (VoiceTool $tool): array => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'parameters' => $tool->parameters(),
        ], array_filter($this->all(), fn (VoiceTool $tool): bool => $this->forVoice($tool->name()))));
    }

    /**
     * Whether the voice may call this tool itself.
     */
    public function forVoice(string $name): bool
    {
        return in_array($name, config('agents.voice_tools'), true);
    }

    /**
     * Validate the model's arguments and run the tool. Invalid arguments are
     * returned to the model as an error so it can correct itself.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function run(VoiceTool $tool, User $user, array $arguments, ToolContext $context): ToolResult
    {
        $validator = Validator::make($arguments, $tool->rules());

        if ($validator->fails()) {
            return ToolResult::error('Argumentos no válidos: '.implode(' ', $validator->errors()->all()));
        }

        // A model points only at ids the server handed out, never at one it made up.
        $invented = $context->byModel() ? $this->refs->unknown($user, $validator->validated()) : null;

        if ($invented !== null) {
            return ToolResult::error("Ese {$invented} no ha salido de ninguna búsqueda ni de la pantalla. Búscalo primero y usa el id que devuelva.");
        }

        $result = $tool->handle($user, $validator->validated(), $context);
        $this->refs->remember($user, $result->output, $result->canvas?->toArray() ?? [], $result->beside?->toArray() ?? []);

        return $result;
    }
}
