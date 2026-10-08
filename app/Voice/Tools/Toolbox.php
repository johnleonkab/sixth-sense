<?php

namespace App\Voice\Tools;

use App\Models\User;
use App\Voice\Tools\Actions\GetActionStatus;
use App\Voice\Tools\Alerts\ListAlerts;
use App\Voice\Tools\Alerts\ResolveAlert;
use App\Voice\Tools\Alerts\ReviewAlert;
use App\Voice\Tools\Calendar\FindCalendarEvents;
use App\Voice\Tools\Calendar\ProposeEventCancellation;
use App\Voice\Tools\Calendar\ProposeEventInvite;
use App\Voice\Tools\Calendar\ProposeEventResponse;
use App\Voice\Tools\Calendar\ProposeEventSummary;
use App\Voice\Tools\Calendar\ProposeNewEvent;
use App\Voice\Tools\Calendar\ShowCalendar;
use App\Voice\Tools\Docs\OpenDocument;
use App\Voice\Tools\Docs\ProposeDocumentComment;
use App\Voice\Tools\Docs\ProposeDocumentEdit;
use App\Voice\Tools\Docs\SearchDocuments;
use App\Voice\Tools\Docs\ShowDocumentResults;
use App\Voice\Tools\Mail\OpenEmail;
use App\Voice\Tools\Mail\OpenEmailAttachment;
use App\Voice\Tools\Mail\ProposeEmailForward;
use App\Voice\Tools\Mail\ProposeEmailReply;
use App\Voice\Tools\Mail\ProposeNewEmail;
use App\Voice\Tools\Mail\SearchEmails;
use App\Voice\Tools\Mail\ShowEmailResults;
use App\Voice\Tools\Screen\ControlViewer;
use App\Voice\Tools\Slack\FindSlackConversations;
use App\Voice\Tools\Slack\FindSlackPeople;
use App\Voice\Tools\Slack\OpenSlackFile;
use App\Voice\Tools\Slack\ProposeSlackMessage;
use App\Voice\Tools\Slack\SearchSlackMessages;
use App\Voice\Tools\Slack\ShowSlackConversation;
use App\Voice\Tools\Slack\ShowSlackUnread;
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
        ProposeEventCancellation::class,
        SearchEmails::class,
        ShowEmailResults::class,
        OpenEmail::class,
        OpenEmailAttachment::class,
        ProposeNewEmail::class,
        ProposeEmailReply::class,
        ProposeEmailForward::class,
        SearchDocuments::class,
        ShowDocumentResults::class,
        OpenDocument::class,
        ProposeDocumentEdit::class,
        ProposeDocumentComment::class,
        ShowSlackUnread::class,
        ShowSlackConversation::class,
        SearchSlackMessages::class,
        FindSlackConversations::class,
        FindSlackPeople::class,
        OpenSlackFile::class,
        ProposeSlackMessage::class,
        ControlViewer::class,
        GetActionStatus::class,
        ListAlerts::class,
        ReviewAlert::class,
        ResolveAlert::class,
    ];

    public function __construct(private Container $container) {}

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
     * Provider-neutral declarations for the voice session setup.
     *
     * @return list<array{name: string, description: string, parameters: array<string, mixed>}>
     */
    public function declarations(): array
    {
        return array_map(fn (VoiceTool $tool): array => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'parameters' => $tool->parameters(),
        ], $this->all());
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

        return $tool->handle($user, $validator->validated(), $context);
    }
}
