<?php

namespace App\Comms\Events;

enum Source: string
{
    case Slack = 'slack';
    case Gmail = 'gmail';
    case Jira = 'jira';
    case Notion = 'notion';
    case Calendar = 'calendar';
    case Drive = 'drive';
    case GitHub = 'github';
    case Clock = 'clock';
    case Model = 'model';
    case User = 'user';
    case Gate = 'gate';
}
