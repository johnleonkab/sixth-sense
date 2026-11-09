<?php

namespace App\Comms\Gate;

/**
 * Write tools the gate knows. Later phases add cases; they are not executable yet.
 */
enum ActionClass: string
{
    case SendSlack = 'send_slack';
    case SendEmailReply = 'send_email_reply';
    case SendEmailNew = 'send_email_new';
    case JiraComment = 'jira_comment';
    case NotionComment = 'notion_comment';
    case CalendarCreate = 'calendar_create';
    case CalendarUpdate = 'calendar_update';
    case NotionCreatePage = 'notion_create_page';
    case DriveShare = 'drive_share';
    case JiraUpdate = 'jira_update';
    case NotionEdit = 'notion_edit';
}
