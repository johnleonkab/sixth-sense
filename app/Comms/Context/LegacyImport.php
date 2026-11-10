<?php

namespace App\Comms\Context;

use App\Models\User;
use App\WorkContext\WorkDigest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Copies what the old stores held into the fact store, once, right before
 * they are dropped: lessons, the nightly digest, the Slack attention
 * profile, accepted Slack preferences, answered questions and the comms
 * documents. It reads the tables directly because their models are gone.
 */
class LegacyImport
{
    public function __construct(private ContextWriter $context) {}

    /**
     * @return int Facts recorded.
     */
    public function run(): int
    {
        $recorded = 0;

        foreach (User::query()->pluck('id') as $userId) {
            $user = User::query()->find($userId);

            if (! $user instanceof User) {
                continue;
            }

            $operations = [
                ...$this->lessons($user),
                ...$this->digest($user),
                ...$this->attention($user),
                ...$this->preferences($user),
                ...$this->answers($user),
            ];

            foreach (array_chunk($operations, 100) as $chunk) {
                $this->context->record($user, FactOrigin::Correction, $chunk, revision: 'legacy-import:'.sha1(json_encode($chunk) ?: ''));
            }

            $recorded += count($operations);
            $this->documents($user);
        }

        return $recorded;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lessons(User $user): array
    {
        if (! Schema::hasTable('intent_lessons')) {
            return [];
        }

        return DB::table('intent_lessons')->where('user_id', $user->id)->orderBy('id')->get()->map(fn (object $lesson): array => [
            'op' => 'add',
            'kind' => 'rule',
            'subject' => $this->context->subject($lesson->about ?? null),
            'attribute' => 'rule',
            'value' => array_filter([
                'text' => Str::limit(trim((string) $lesson->body), 240),
                'topic' => $lesson->topic ?? null,
                'name' => filled($lesson->about ?? null) ? (string) $lesson->about : null,
            ], fn (mixed $value): bool => $value !== null),
            'audiences' => filled($lesson->layer ?? null) ? [Audience::fromLayer((string) $lesson->layer)->value] : [],
            'refs' => array_values(array_filter([
                isset($lesson->inbox_item_id) ? "alert:{$lesson->inbox_item_id}" : null,
                isset($lesson->proposal_outcome_id) ? "outcome:{$lesson->proposal_outcome_id}" : null,
            ])),
            'origin' => FactOrigin::Correction->value,
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function digest(User $user): array
    {
        if (! Schema::hasColumn('work_contexts', 'digest')) {
            return [];
        }

        $row = DB::table('work_contexts')->where('user_id', $user->id)->first();

        if ($row === null || blank($row->digest)) {
            return [];
        }

        try {
            $digest = WorkDigest::fromArray(json_decode((string) $row->digest, true, flags: JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            return [];
        }

        return ContextHarvest::operations($digest, CarbonImmutable::parse($row->generated_at ?? now()));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function attention(User $user): array
    {
        if (! Schema::hasTable('slack_attention_profiles')) {
            return [];
        }

        $profile = DB::table('slack_attention_profiles')->where('user_id', $user->id)->first();

        if ($profile === null || blank($profile->body)) {
            return [];
        }

        return [[
            'op' => 'set',
            'kind' => 'channel',
            'subject' => 'slack',
            'attribute' => 'profile',
            'value' => ['text' => (string) $profile->body, 'signals' => json_decode((string) $profile->signals, true) ?: []],
            'audiences' => [Audience::Triage->value],
            'origin' => FactOrigin::Observed->value,
        ]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function preferences(User $user): array
    {
        if (! Schema::hasColumn('slack_preference_rules', 'body')) {
            return [];
        }

        return DB::table('slack_preference_rules')
            ->where('user_id', $user->id)
            ->where('status', 'accepted')
            ->orderBy('id')
            ->get()
            ->map(fn (object $rule): array => [
                'op' => 'add',
                'kind' => 'channel',
                'subject' => 'slack',
                'attribute' => 'rule',
                'value' => ['text' => Str::limit(trim((string) $rule->body), 240)],
                'audiences' => [Audience::Triage->value],
                'refs' => ["slack-rule:{$rule->id}"],
                'origin' => FactOrigin::Correction->value,
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function answers(User $user): array
    {
        return DB::table('knowledge_gaps')
            ->where('user_id', $user->id)
            ->where('status', 'answered')
            ->whereIn('kind', ['term', 'meeting'])
            ->whereNotNull('answer')
            ->orderBy('id')
            ->get()
            ->map(fn (object $gap): array => [
                'op' => 'set',
                'kind' => 'term',
                'subject' => "{$gap->kind}:{$gap->subject_key}",
                'attribute' => 'meaning',
                'value' => ['text' => trim((string) $gap->answer), 'name' => (string) $gap->subject, 'question' => (string) $gap->question],
                'audiences' => [],
                'refs' => ["gap:{$gap->id}"],
                'origin' => FactOrigin::User->value,
            ])->all();
    }

    /**
     * Comms documents become corpus rows; what the index already has wins.
     */
    private function documents(User $user): void
    {
        if (! Schema::hasTable('comms_documents')) {
            return;
        }

        foreach (DB::table('comms_documents')->where('user_id', $user->id)->get() as $document) {
            $source = match ($document->kind) {
                'code', 'archive' => 'archive',
                'jira', 'notion', 'meeting' => $document->kind,
                default => 'memory',
            };

            $exists = DB::table('knowledge_documents')->where('user_id', $user->id)->where('source', $source)->where('ref', $document->ref)->exists();

            if ($exists) {
                continue;
            }

            DB::table('knowledge_documents')->insert([
                'user_id' => $user->id,
                'source' => $source,
                'ref' => Str::limit((string) $document->ref, 191, ''),
                'title' => Str::limit((string) $document->title, 500, ''),
                'summary' => (string) $document->body,
                'topic' => $document->topic,
                'created_at' => $document->created_at ?? now(),
                'updated_at' => $document->updated_at ?? now(),
            ]);
        }
    }
}
