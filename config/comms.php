<?php

$provider = env('AI_TEXT_PROVIDER', 'deepseek');
$fallback = $provider === 'gemini' ? 'deepseek' : 'gemini';

$task = static function (string $tier) use ($provider, $fallback): array {
    return [
        'tier' => $tier,
        'provider' => $provider,
        'fallback' => $fallback,
        'prompt' => 1,
    ];
};

return [

    /*
    |--------------------------------------------------------------------------
    | Tasks
    |--------------------------------------------------------------------------
    |
    | A call site names a task. The tier and the provider live here, with one
    | fallback provider when the first one throws. Swapping a model is a
    | config change. Prompt files are resources/prompts/comms/{task}/v{n}.md.
    |
    */

    'tasks' => [
        'triage' => $task('small'),
        'route' => $task('small'),
        'watcher_check' => $task('small'),
        'embed' => $task('embeddings'),
        'match' => $task('mid'),
        'extract_actions' => $task('mid'),
        'draft' => $task('mid'),
        'summarize' => $task('mid'),
        'answer' => $task('large'),
        'contradiction' => $task('large'),
        'verify_claims' => $task('large'),
    ],

    /*
    | How a communications tier maps onto the light and capable models in
    | config/ai.php. large_model replaces the capable model when it is set.
    */

    'ai_tiers' => [
        'rules' => 'light',
        'embeddings' => 'light',
        'small' => 'light',
        'mid' => 'capable',
        'large' => 'capable',
    ],

    'large_model' => env('COMMS_LARGE_MODEL'),

    /*
    | These tasks hand a low-confidence answer to the next tier, with that
    | answer as a hint. The floor is the minimum that still counts.
    */

    'escalate_on_confidence' => ['triage', 'route', 'watcher_check', 'match'],

    'confidence_floor' => 0.6,

    'candidates' => [
        'max' => 10,
        'settled_window_days' => 14,
        'duplicate_similarity' => 92,
        'duplicate_similarity_same_channel' => 78,
    ],

    'quiet_after_days' => 7,

    /*
    | Shadow stays on until cutover. The clock records what it would say
    | and the shadow tab shows it. Nothing is pushed and nothing is spoken.
    */

    /*
    | Per-source cutover. Off keeps the current alert brain. On, that source
    | is ingested by the communications pipeline and leaves the shadow tab.
    */

    'pipeline' => [
        'slack' => (bool) env('COMMS_PIPELINE_SLACK', false),
        'gmail' => (bool) env('COMMS_PIPELINE_GMAIL', false),
    ],

    'notify' => [
        'shadow' => true,
        'digest_at' => ['09:30', '15:30'],
        'followup_importance' => 60,
        'expire_importance' => 25,
        'expire_after_days' => 7,
        'stale_after_business_days' => 5,
        'second_miss_importance' => 80,
    ],

    /*
    | A settle is rejected when the whole message is one of these phrases.
    | The matcher may still acknowledge the intent.
    */

    'acknowledgements' => ['👍', 'ok', 'vale', 'perfecto', 'on it', 'lo miro', 'ahora lo veo', 'noted'],

    /*
    | Sender and subject fragments. The first match wins. delivery stops
    | before matching; ask continues into the matcher.
    */

    'inbox_rules' => [
        ['from' => 'calendar-notification@google.com', 'subject' => 'accepted', 'label' => 'delivery'],
        ['from' => 'drive-shares-dm-noreply@google.com', 'subject' => 'shared', 'label' => 'delivery'],
        ['from' => 'noreply@github.com', 'subject' => 'review requested', 'label' => 'ask'],
        ['from' => 'jira@', 'subject' => 'assigned to you', 'label' => 'ask'],
        ['from' => 'notifications@google.com', 'subject' => 'invitation', 'label' => 'ask'],
    ],

    /*
    | Token budgets. High on purpose: when one is spent the pipeline shows
    | the item with no draft instead of calling a model.
    */

    'calendar' => [
        'lookahead_minutes' => 20,
        'planning_days' => 2,
        'focus_titles' => ['focus', 'foco'],
        'planning_titles' => ['planning', 'planificación'],
        'one_on_one_titles' => ['1:1', '1-1'],
        'interview_titles' => ['interview', 'entrevista'],
        'demo_titles' => ['demo'],
    ],

    'budgets' => [
        'per_message' => 50_000,
        'per_run' => 200_000,
        'per_day' => 2_000_000,
    ],

];
