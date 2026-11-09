<?php

namespace App\Comms\Events;

/**
 * What an appended row is. The catalogue matches the implementation plan.
 * Question and NoDraft are the phase 0 markers for a schema that never
 * validated and a budget that was already spent. Later phases turn them
 * into questions and notifications.
 */
enum EventType: string
{
    case MessageReceived = 'message.received';
    case MessageSent = 'message.sent';
    case MessageEdited = 'message.edited';
    case MessageDeleted = 'message.deleted';
    case MessageFlagged = 'message.flagged';
    case PersonIdentified = 'person.identified';
    case StateApplied = 'state.applied';
    case UserConfigured = 'user.configured';
    case SourceChanged = 'source.changed';
    case ModelDecided = 'model.decided';
    case ClockDue = 'clock.due';
    case ClockStale = 'clock.stale';
    case ClockLookahead = 'clock.lookahead';
    case ClockDigest = 'clock.digest';
    case UserCorrected = 'user.corrected';
    case UserAnswered = 'user.answered';
    case ActionExecuted = 'action.executed';
    case ActionUndone = 'action.undone';
    case ActionFailed = 'action.failed';
    case Question = 'model.question';
    case NoDraft = 'model.no_draft';
}
