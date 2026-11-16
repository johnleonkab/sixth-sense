<?php

namespace App\Comms\Surface;

use App\Comms\Gate\ActionClass;
use App\Comms\Gate\ProposalStatus;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsPlaybookRun;
use App\Models\Comms\CommsProposal;
use App\Models\Comms\CommsWatcher;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The checklist inside an intent card. Intent steps, the playbook run,
 * proposals, and watchers. Steps that wait on the same ids are one group.
 */
class PlanChecklist
{
    /**
     * @return list<array{after: list<string>, steps: list<array{id: string, text: string, status: string, channel: ?string, preview: ?string, waiting_on: ?array{name: string, since: string}}>}>
     */
    public function for(CommsIntent $intent): array
    {
        $steps = [
            ...$this->intentSteps($intent),
            ...$this->runs($intent),
            ...$this->proposals($intent),
            ...$this->watchers($intent),
        ];

        return $this->groups($steps);
    }

    /**
     * @return list<array{id: string, after: list<string>, text: string, status: string, channel: ?string, preview: ?string, waiting_on: ?array{name: string, since: string}}>
     */
    private function intentSteps(CommsIntent $intent): array
    {
        $steps = [];

        foreach ($intent->steps ?? [] as $step) {
            if (! is_array($step)) {
                continue;
            }

            $id = trim((string) ($step['id'] ?? ''));
            $what = trim((string) ($step['what'] ?? $step['text'] ?? ''));

            if ($id === '' || $what === '') {
                continue;
            }

            $steps[] = $this->step(
                id: $id,
                after: $this->after($step['after'] ?? []),
                text: $this->sentence(is_string($step['condition'] ?? null) ? $step['condition'] : null, $what),
                status: $this->status((string) ($step['status'] ?? 'waiting')),
                channel: $this->text($step['channel'] ?? $step['tool'] ?? null),
                preview: $this->text($step['preview'] ?? null),
                waitingOn: null,
            );
        }

        return $steps;
    }

    /**
     * @return list<array{id: string, after: list<string>, text: string, status: string, channel: ?string, preview: ?string, waiting_on: ?array{name: string, since: string}}>
     */
    private function runs(CommsIntent $intent): array
    {
        $steps = [];

        foreach (CommsPlaybookRun::query()->where('intent_id', $intent->id)->orderBy('id')->get() as $run) {
            $context = is_array($run->context) ? $run->context : [];
            $what = trim((string) ($context['what'] ?? $run->playbook));

            $steps[] = $this->step(
                id: 'run:'.$run->id,
                after: $this->after($context['after'] ?? []),
                text: $this->sentence($this->text($context['condition'] ?? null), $what),
                status: match ($run->state) {
                    'done', 'met' => 'done',
                    'running' => 'running',
                    'awaiting_approval' => 'needs_approval',
                    'blocked', 'failed' => 'blocked',
                    'cancelled', 'skipped', 'rejected' => 'skipped',
                    default => 'waiting',
                },
                channel: $this->text($context['channel'] ?? null),
                preview: $this->text($context['preview'] ?? null),
                waitingOn: null,
            );
        }

        return $steps;
    }

    /**
     * @return list<array{id: string, after: list<string>, text: string, status: string, channel: ?string, preview: ?string, waiting_on: ?array{name: string, since: string}}>
     */
    private function proposals(CommsIntent $intent): array
    {
        $steps = [];

        foreach (CommsProposal::query()->where('intent_id', $intent->id)->orderBy('id')->get() as $proposal) {
            $payload = is_array($proposal->payload) ? $proposal->payload : [];

            $steps[] = $this->step(
                id: 'proposal:'.$proposal->id,
                after: $this->after($payload['after'] ?? []),
                text: $this->sentence(null, $proposal->action_class->value),
                status: match ($proposal->status) {
                    ProposalStatus::Pending => 'needs_approval',
                    ProposalStatus::Approved, ProposalStatus::Edited => 'running',
                    ProposalStatus::Executed => 'done',
                    ProposalStatus::Failed => 'blocked',
                    ProposalStatus::Rejected, ProposalStatus::Undone, ProposalStatus::Expired => 'skipped',
                },
                channel: $this->channel($proposal->action_class),
                preview: $this->text($payload['text'] ?? $payload['preview'] ?? null),
                waitingOn: null,
            );
        }

        return $steps;
    }

    /**
     * @return list<array{id: string, after: list<string>, text: string, status: string, channel: ?string, preview: ?string, waiting_on: ?array{name: string, since: string}}>
     */
    private function watchers(CommsIntent $intent): array
    {
        $steps = [];

        foreach (CommsWatcher::query()->where('intent_id', $intent->id)->orderBy('id')->get() as $watcher) {
            $condition = is_array($watcher->condition) ? $watcher->condition : [];
            $name = $this->text($condition['person'] ?? $condition['name'] ?? null);
            $what = trim((string) ($condition['expects'] ?? 'esperar respuesta'));
            $status = match ($watcher->state) {
                'met', 'done' => 'done',
                'cancelled' => 'skipped',
                default => 'waiting',
            };
            $since = $watcher->created_at instanceof CarbonInterface
                ? CarbonImmutable::instance($watcher->created_at)->toIso8601String()
                : null;

            $steps[] = $this->step(
                id: 'watcher:'.$watcher->id,
                after: [],
                text: $this->sentence($name !== null ? 'Si '.$name.' confirma' : null, $what),
                status: $status,
                channel: null,
                preview: null,
                waitingOn: $status === 'waiting' && $name !== null && $since !== null
                    ? ['name' => $name, 'since' => $since]
                    : null,
            );
        }

        return $steps;
    }

    /**
     * @param  list<array{id: string, after: list<string>, text: string, status: string, channel: ?string, preview: ?string, waiting_on: ?array{name: string, since: string}}>  $steps
     * @return list<array{after: list<string>, steps: list<array{id: string, text: string, status: string, channel: ?string, preview: ?string, waiting_on: ?array{name: string, since: string}}>}>
     */
    private function groups(array $steps): array
    {
        $buckets = [];

        foreach ($steps as $step) {
            $after = $step['after'];
            sort($after);
            $key = implode("\0", $after);
            $buckets[$key]['after'] = $after;
            unset($step['after']);
            $buckets[$key]['steps'][] = $step;
        }

        $remaining = array_values($buckets);
        $ordered = [];
        $done = [];

        while ($remaining !== []) {
            $ready = [];
            $later = [];

            foreach ($remaining as $group) {
                $waiting = array_filter(
                    $group['after'],
                    fn (string $id): bool => ! in_array($id, $done, true),
                );

                if ($waiting === []) {
                    $ready[] = $group;
                } else {
                    $later[] = $group;
                }
            }

            if ($ready === []) {
                array_push($ordered, ...$later);

                break;
            }

            foreach ($ready as $group) {
                $ordered[] = $group;

                foreach ($group['steps'] as $step) {
                    $done[] = $step['id'];
                }
            }

            $remaining = $later;
        }

        return $ordered;
    }

    /**
     * @param  list<string>  $after
     * @param  ?array{name: string, since: string}  $waitingOn
     * @return array{id: string, after: list<string>, text: string, status: string, channel: ?string, preview: ?string, waiting_on: ?array{name: string, since: string}}
     */
    private function step(string $id, array $after, string $text, string $status, ?string $channel, ?string $preview, ?array $waitingOn): array
    {
        return [
            'id' => $id,
            'after' => $after,
            'text' => $text,
            'status' => $status,
            'channel' => $channel,
            'preview' => $preview,
            'waiting_on' => $waitingOn,
        ];
    }

    /**
     * @return list<string>
     */
    private function after(mixed $after): array
    {
        if (! is_array($after)) {
            return [];
        }

        return array_values(array_filter(
            $after,
            fn (mixed $id): bool => is_string($id) && $id !== '',
        ));
    }

    private function sentence(?string $condition, string $what): string
    {
        $condition = trim((string) $condition);

        if ($condition === '' || str_starts_with($what, $condition)) {
            return $what;
        }

        return $condition.': '.$what;
    }

    private function status(string $status): string
    {
        $status = str_replace(' ', '_', strtolower(trim($status)));

        return in_array($status, ['done', 'running', 'needs_approval', 'waiting', 'blocked', 'skipped'], true)
            ? $status
            : 'waiting';
    }

    private function channel(ActionClass $class): string
    {
        return match ($class) {
            ActionClass::SendSlack => 'slack',
            ActionClass::SendEmailReply, ActionClass::SendEmailNew => 'gmail',
            ActionClass::JiraComment, ActionClass::JiraUpdate => 'jira',
            ActionClass::NotionComment, ActionClass::NotionCreatePage, ActionClass::NotionEdit => 'notion',
            ActionClass::CalendarCreate, ActionClass::CalendarUpdate => 'calendar',
            ActionClass::DriveShare => 'drive',
        };
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
