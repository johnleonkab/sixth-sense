<?php

namespace App\Comms\Evaluation;

use App\Comms\Events\EventType;
use App\Comms\Linking\CriticVerdict;
use App\Comms\People\Relation;
use App\Models\AiUsage;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsEvalRun;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsGoldenLabel;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsReconcileChange;
use App\Models\User;

/**
 * One weekly reading of the golden set. It replays stored labels and
 * decisions. It does not call a model and it does not change a prompt.
 */
class WeeklyEval
{
    /** @var list<string> */
    public const METRICS = [
        'retrieval_topic',
        'retrieval_intent',
        'attach_precision',
        'uncertain_share',
        'uncertain_moved_share',
        'needs_user_precision',
        'needs_user_recall',
        'critic_refutation_rate',
        'critic_agreement',
        'corrections_per_100',
    ];

    public function __construct(private RetrievalMetric $retrieval) {}

    public function record(?User $user): CommsEvalRun
    {
        return CommsEvalRun::query()->create([
            'user_id' => $user?->id,
            'kind' => 'weekly',
            'prompt_versions' => $this->versions(),
            'metrics' => $this->measure($user),
            'passed' => null,
        ]);
    }

    /**
     * @return array<string, float|null>
     */
    public function measure(?User $user): array
    {
        $retrieval = $this->retrieval->measure($user);
        $needs = $this->needsUser($user);
        $critic = $this->critic($user);

        return [
            'retrieval_topic' => $this->rate($retrieval['topic']['hits'], $retrieval['topic']['total']),
            'retrieval_intent' => $this->rate($retrieval['intent']['hits'], $retrieval['intent']['total']),
            'attach_precision' => $this->attachPrecision($user),
            'uncertain_share' => $this->uncertainShare($user),
            'uncertain_moved_share' => $this->uncertainMoved($user),
            'needs_user_precision' => $needs['precision'],
            'needs_user_recall' => $needs['recall'],
            'critic_refutation_rate' => $critic['rate'],
            'critic_agreement' => $this->agreement($user, $critic['refuted']),
            'corrections_per_100' => $this->correctionsPerHundred($user),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function versions(): array
    {
        $versions = [];

        foreach (['segment', 'link', 'extract', 'critic', 'dossier_write', 'reconcile', 'topic_build', 'ask_pattern', 'identity_resolve'] as $task) {
            $versions[$task] = (int) config("comms.tasks.{$task}.prompt");
        }

        return $versions;
    }

    private function attachPrecision(?User $user): ?float
    {
        $labels = CommsGoldenLabel::query()
            ->when($user !== null, fn ($query) => $query->whereBelongsTo($user))
            ->whereNotNull('message_id')
            ->whereNotNull('expected')
            ->orderBy('id')
            ->get();
        $messageIds = [];
        $expected = [];

        foreach ($labels as $label) {
            $intent = $label->expected['intent'] ?? null;
            $intent = is_int($intent) ? $intent : (is_string($intent) && ctype_digit($intent) ? (int) $intent : null);

            if ($intent === null || $label->message_id === null) {
                continue;
            }

            $messageIds[] = $label->message_id;
            $expected[$label->message_id] = $intent;
        }

        if ($messageIds === []) {
            return null;
        }

        $links = CommsConversationLink::query()
            ->when($user !== null, fn ($query) => $query->whereBelongsTo($user))
            ->where('uncertain', false)
            ->whereIn('message_id', $messageIds)
            ->orderBy('id')
            ->get()
            ->keyBy('message_id');
        $hits = 0;
        $total = 0;

        foreach ($expected as $messageId => $intentId) {
            $link = $links->get($messageId);

            if ($link === null) {
                continue;
            }

            $total++;
            $hits += $link->intent_id === $intentId ? 1 : 0;
        }

        return $this->rate($hits, $total);
    }

    private function uncertainShare(?User $user): ?float
    {
        $links = CommsConversationLink::query()->when($user !== null, fn ($query) => $query->whereBelongsTo($user));
        $total = (clone $links)->count();

        return $this->rate((clone $links)->where('uncertain', true)->count(), $total);
    }

    private function uncertainMoved(?User $user): ?float
    {
        $uncertain = CommsConversationLink::query()
            ->when($user !== null, fn ($query) => $query->whereBelongsTo($user))
            ->where('uncertain', true)
            ->count();
        $moved = CommsReconcileChange::query()
            ->when($user !== null, fn ($query) => $query->whereBelongsTo($user))
            ->where('op', 'relink')
            ->whereIn('disposition', ['applied', 'undone'])
            ->count();

        return $this->rate($moved, $uncertain + $moved);
    }

    /**
     * @return array{precision: float|null, recall: float|null}
     */
    private function needsUser(?User $user): array
    {
        $labels = CommsGoldenLabel::query()
            ->when($user !== null, fn ($query) => $query->whereBelongsTo($user))
            ->where('kind', 'weak_label')
            ->whereIn('label', ['acted', 'ignored'])
            ->orderBy('id')
            ->get();
        $counts = ['tp' => 0, 'fp' => 0, 'fn' => 0];

        foreach ($labels as $label) {
            $message = $label->message_id === null ? null : CommsMessage::query()->find($label->message_id);

            if (! $message instanceof CommsMessage) {
                continue;
            }

            $predicted = $this->predicted($message);
            $actual = $label->label === 'acted';

            if ($predicted && $actual) {
                $counts['tp']++;
            } elseif ($predicted) {
                $counts['fp']++;
            } elseif ($actual) {
                $counts['fn']++;
            }
        }

        return [
            'precision' => $this->rate($counts['tp'], $counts['tp'] + $counts['fp']),
            'recall' => $this->rate($counts['tp'], $counts['tp'] + $counts['fn']),
        ];
    }

    private function predicted(CommsMessage $message): bool
    {
        $selfId = CommsPerson::query()->whereBelongsTo($message->user)->where('relation', Relation::Self)->value('id');
        $intentIds = CommsConversationLink::query()
            ->whereBelongsTo($message->user)
            ->where('message_id', $message->id)
            ->pluck('intent_id');

        foreach ($intentIds as $intentId) {
            $intent = CommsIntent::query()->find($intentId);

            if (! $intent instanceof CommsIntent) {
                continue;
            }

            if ($intent->possible_ask) {
                return true;
            }

            if ($selfId !== null && $intent->participants()->where('role', 'responder')->where('person_id', $selfId)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{refuted: int, rate: float|null}
     */
    private function critic(?User $user): array
    {
        $rows = AiUsage::query()
            ->when($user !== null, fn ($query) => $query->whereBelongsTo($user))
            ->where('task', 'critic')
            ->where('status', 'ok')
            ->orderBy('id')
            ->get();
        $checked = 0;
        $refuted = 0;

        foreach ($rows as $row) {
            if (! is_array($row->output)) {
                continue;
            }

            $texts = $row->window_id === null
                ? []
                : CommsMessage::query()->where('window_id', $row->window_id)->orderBy('id')->pluck('text')->map(fn (mixed $text): string => (string) $text)->all();
            $checked++;
            $refuted += CriticVerdict::read($row->output, $texts)->refuted ? 1 : 0;
        }

        return [
            'refuted' => $refuted,
            'rate' => $this->rate($refuted, $checked),
        ];
    }

    private function agreement(?User $user, int $refuted): ?float
    {
        if ($refuted === 0) {
            return null;
        }

        $agree = 0;
        $disagree = 0;
        $corrections = CommsEvent::query()
            ->when($user !== null, fn ($query) => $query->whereBelongsTo($user))
            ->where('type', EventType::UserCorrected)
            ->orderBy('id')
            ->get();

        foreach ($corrections as $correction) {
            $action = (string) ($correction->normalized['answer'] ?? $correction->normalized['action'] ?? '');

            if (in_array($action, ['not_for_me', 'close', 'done', 'igual'], true)) {
                $agree++;
            } elseif (in_array($action, ['still_open', 'no_es_igual'], true)) {
                $disagree++;
            }
        }

        return $this->rate($agree, $agree + $disagree);
    }

    private function correctionsPerHundred(?User $user): ?float
    {
        $messages = CommsMessage::query()
            ->when($user !== null, fn ($query) => $query->whereBelongsTo($user))
            ->where('direction', 'in')
            ->whereNull('deleted_at')
            ->count();
        $corrections = CommsEvent::query()
            ->when($user !== null, fn ($query) => $query->whereBelongsTo($user))
            ->where('type', EventType::UserCorrected)
            ->count();

        if ($messages === 0) {
            return null;
        }

        return round($corrections / $messages * 100, 4);
    }

    private function rate(int $numerator, int $denominator): ?float
    {
        if ($denominator === 0) {
            return null;
        }

        return round($numerator / $denominator, 4);
    }
}
