<?php

namespace App\Wingman\Closing;

use App\Connectors\Issues\IssueTracker;
use App\Connectors\Issues\NewIssue;
use App\Events\WingmanMeetingUpdated;
use App\Models\WingmanAction;
use App\Models\WingmanMeeting;
use App\Wingman\Copilot\Finding;
use App\Wingman\Copilot\Lookups;
use App\Wingman\Notes\LiveNotes;
use App\Wingman\Notes\MeetingNotes;
use Illuminate\Support\Str;
use Throwable;

/**
 * Closes a call's document once the person leaves: the last lines into the
 * notes, then what they have left to do, each thing made ready to review:
 * the message written, the meeting with a slot, the issue, time for a task.
 */
class MeetingCloser
{
    public function __construct(
        private LiveNotes $notes,
        private FollowUpPlanner $planner,
        private Lookups $lookups,
        private IssueTracker $issues,
    ) {}

    /**
     * @return list<WingmanAction> What was made ready.
     */
    public function close(WingmanMeeting $meeting): array
    {
        $this->notes->take($meeting);
        $meeting->refresh();

        $notes = MeetingNotes::fromArray($meeting->notes);
        $ready = [];

        if ($notes->commitments !== [] || $notes->decisions !== []) {
            foreach ($this->planner->plan($meeting, $notes, $meeting->actions()->pluck('title')->all()) as $followUp) {
                $action = $this->prepare($meeting, $followUp);

                if ($action !== null && ! $meeting->actions()->where('dedupe_key', $action['dedupe_key'])->exists()) {
                    $ready[] = $meeting->actions()->create($action);
                }
            }
        }

        $meeting->update(['closed_at' => now()]);
        WingmanMeetingUpdated::dispatch($meeting);

        return $ready;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function prepare(WingmanMeeting $meeting, FollowUp $followUp): ?array
    {
        try {
            return match ($followUp->kind) {
                'message' => $this->fromCard($meeting, $followUp, new Finding('message', $followUp->title, 1, true, $followUp->details)),
                'meeting' => $this->fromCard($meeting, $followUp, new Finding('slot', $followUp->title, 1, false, $followUp->details)),
                'issue' => $this->issue($meeting, $followUp),
                'task' => $this->task($followUp),
                default => null,
            };
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * A message or a meeting, made ready the way Wingman's cards are.
     *
     * @return array<string, mixed>|null
     */
    private function fromCard(WingmanMeeting $meeting, FollowUp $followUp, Finding $finding): ?array
    {
        $card = $this->lookups->resolve($meeting, $finding);

        if ($card === null || $card['action'] === null) {
            return null;
        }

        return [
            'kind' => $followUp->kind,
            'title' => $followUp->title,
            'detail' => $card['action']['preview'] ?? $card['body'],
            'type' => $card['action']['type'],
            'label' => $card['action']['label'],
            'payload' => $card['action']['payload'],
            'dedupe_key' => $card['dedupe_key'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function issue(WingmanMeeting $meeting, FollowUp $followUp): ?array
    {
        $site = collect($this->issues->rules($meeting->user))->first(fn (array $site): bool => $site['rules']->projects() !== []);

        if ($site === null) {
            return null;
        }

        $project = $site['rules']->projects()[0];
        $summary = Str::limit((string) ($followUp->details['summary'] ?? $followUp->title), 200, '');
        $type = in_array($followUp->details['type'] ?? null, ['Task', 'Story', 'Bug'], true) ? $followUp->details['type'] : 'Task';
        $description = trim(($followUp->details['description'] ?? '')."\n\nDe la reunión «{$meeting->title}».");

        return [
            'kind' => 'issue',
            'title' => $followUp->title,
            'detail' => "{$project} · {$type} · {$summary}",
            'type' => 'jira.create',
            'label' => "Crear «{$summary}» en {$project}",
            'payload' => ['site' => $site['account_id'], 'issue' => (new NewIssue($project, $type, $summary, $description))->toArray()],
            'dedupe_key' => 'issue:'.Str::limit(Str::slug($summary), 80, ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function task(FollowUp $followUp): array
    {
        $title = Str::limit((string) ($followUp->details['title'] ?? $followUp->title), 140, '');

        return [
            'kind' => 'task',
            'title' => $followUp->title,
            'detail' => 'Te busco hueco en tu calendario'.(isset($followUp->details['deadline']) ? " antes del {$followUp->details['deadline']}" : '').'.',
            'type' => 'agenda.schedule',
            'label' => "Reservar tiempo: «{$title}»",
            'payload' => array_filter([
                'origin' => 'wingman',
                'title' => $title,
                'context' => $followUp->details['context'] ?? null,
                'effort_minutes' => isset($followUp->details['effort_minutes']) ? (int) $followUp->details['effort_minutes'] : null,
                'deadline' => $followUp->details['deadline'] ?? null,
            ], fn (mixed $value): bool => $value !== null),
            'dedupe_key' => 'task:'.Str::limit(Str::slug($title), 80, ''),
        ];
    }
}
