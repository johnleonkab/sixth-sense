<?php

namespace App\Comms\Gate;

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Messages\Direction;
use App\Comms\Messages\Message;
use App\Comms\Messages\Party;
use App\Comms\People\Relation;
use App\Models\Comms\CommsIdentity;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsProposal;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The sent message comes back through the log, linked to the proposal.
 * Internal writes (calendar blocks, Notion pages, Drive) stay ActionExecuted only.
 */
class ProposalEcho
{
    public function __construct(private EventLog $log) {}

    public function record(User $user, CommsProposal $proposal, string $externalId): void
    {
        $source = match ($proposal->action_class) {
            ActionClass::SendSlack => Source::Slack,
            ActionClass::JiraComment, ActionClass::JiraUpdate => Source::Jira,
            ActionClass::NotionComment, ActionClass::NotionCreatePage, ActionClass::NotionEdit => Source::Notion,
            ActionClass::CalendarCreate, ActionClass::CalendarUpdate => Source::Calendar,
            ActionClass::DriveShare => Source::Drive,
            default => Source::Gmail,
        };
        $executed = $this->log->append(
            user: $user,
            type: EventType::ActionExecuted,
            source: Source::Gate,
            externalId: $externalId,
            payload: null,
            normalized: [
                'proposal_id' => $proposal->id,
                'action_class' => $proposal->action_class->value,
                'external_id' => $externalId,
            ],
            causation: null,
            occurredAt: CarbonImmutable::now(),
        );

        if (! $this->isMessage($proposal->action_class)) {
            return;
        }

        $self = CommsPerson::query()->whereBelongsTo($user)->where('relation', Relation::Self)->with('identities')->first();
        $identity = $self?->identities->first(fn (CommsIdentity $row): bool => $row->source === $source) ?? $self?->identities->first();

        if ($self === null || $identity === null) {
            return;
        }

        $message = new Message(
            externalId: $externalId,
            source: $source,
            channel: (string) ($proposal->target['channel'] ?? $proposal->payload['to'] ?? ''),
            thread: isset($proposal->target['thread']) ? (string) $proposal->target['thread'] : null,
            author: new Party($identity->external_id, $identity->email, $self->display_name),
            recipients: [],
            direction: Direction::Outgoing,
            at: CarbonImmutable::now(),
            text: (string) ($proposal->payload['text'] ?? ''),
        );

        $this->log->append(
            user: $user,
            type: EventType::MessageSent,
            source: $source,
            externalId: $externalId,
            payload: null,
            normalized: $message->toArray(),
            causation: $executed,
            occurredAt: $message->at,
        );
    }

    private function isMessage(ActionClass $class): bool
    {
        return match ($class) {
            ActionClass::SendSlack,
            ActionClass::SendEmailReply,
            ActionClass::SendEmailNew,
            ActionClass::JiraComment,
            ActionClass::NotionComment => true,
            default => false,
        };
    }
}
