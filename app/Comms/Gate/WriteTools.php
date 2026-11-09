<?php

namespace App\Comms\Gate;

use App\Comms\Gate\WriteTools\CreateCalendarEvent;
use App\Comms\Gate\WriteTools\CreateNotionPage;
use App\Comms\Gate\WriteTools\EditNotionPage;
use App\Comms\Gate\WriteTools\PostJiraComment;
use App\Comms\Gate\WriteTools\PostNotionComment;
use App\Comms\Gate\WriteTools\SendEmail;
use App\Comms\Gate\WriteTools\SendSlackMessage;
use App\Comms\Gate\WriteTools\ShareDriveFile;
use App\Comms\Gate\WriteTools\UpdateCalendarEvent;
use App\Comms\Gate\WriteTools\UpdateJiraIssue;
use App\Models\Comms\CommsProposal;
use App\Models\User;

/**
 * The only caller of the write tools is the pending-action executor.
 */
class WriteTools
{
    public function __construct(
        private SendSlackMessage $slack,
        private SendEmail $email,
        private PostJiraComment $jira,
        private PostNotionComment $notion,
        private CreateCalendarEvent $calendar,
        private UpdateCalendarEvent $calendarUpdate,
        private CreateNotionPage $notionPage,
        private ShareDriveFile $drive,
        private UpdateJiraIssue $jiraUpdate,
        private EditNotionPage $notionEdit,
    ) {}

    public function send(User $user, CommsProposal $proposal): string
    {
        return match ($proposal->action_class) {
            ActionClass::SendSlack => $this->slack->send($user, $proposal),
            ActionClass::SendEmailReply, ActionClass::SendEmailNew => $this->email->send($user, $proposal),
            ActionClass::JiraComment => $this->jira->send($proposal),
            ActionClass::NotionComment => $this->notion->send($proposal),
            ActionClass::CalendarCreate => $this->calendar->send($proposal),
            ActionClass::CalendarUpdate => $this->calendarUpdate->send($proposal),
            ActionClass::NotionCreatePage => $this->notionPage->send($proposal),
            ActionClass::DriveShare => $this->drive->send($proposal),
            ActionClass::JiraUpdate => $this->jiraUpdate->send($proposal),
            ActionClass::NotionEdit => $this->notionEdit->send($proposal),
        };
    }
}
