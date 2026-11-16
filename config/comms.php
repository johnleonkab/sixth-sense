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

        /*
        | Linking by conversation window (docs/execution-plan.md 1.1). Each
        | names its provider: DeepSeek reads, Gemini criticises, so the
        | critic does not share the extractor's blind spots. `runs` is how
        | many times the task runs on the same input; code compares them.
        | `extract` is the "match v2" of the design documents.
        */
        'segment' => ['tier' => 'large', 'provider' => 'deepseek', 'fallback' => 'gemini', 'prompt' => 1],
        'link' => ['tier' => 'large', 'provider' => 'deepseek', 'fallback' => 'gemini', 'prompt' => 1, 'runs' => 3],
        'extract' => ['tier' => 'large', 'provider' => 'deepseek', 'fallback' => 'gemini', 'prompt' => 1, 'runs' => 3],
        'critic' => ['tier' => 'large', 'provider' => 'gemini', 'fallback' => 'deepseek', 'prompt' => 1],
        'dossier_write' => ['tier' => 'large', 'provider' => 'deepseek', 'fallback' => 'gemini', 'prompt' => 1],
        'reconcile' => ['tier' => 'large', 'provider' => 'deepseek', 'fallback' => 'gemini', 'prompt' => 1],
        'topic_build' => ['tier' => 'large', 'provider' => 'deepseek', 'fallback' => 'gemini', 'prompt' => 1],
        'ask_pattern' => ['tier' => 'large', 'provider' => 'deepseek', 'fallback' => 'gemini', 'prompt' => 1],
        'identity_resolve' => ['tier' => 'large', 'provider' => 'deepseek', 'fallback' => 'gemini', 'prompt' => 1],
    ],

    /*
    | Linking by conversation window. Off keeps today's per-message matcher.
    | Every number here is a starting guess, tuned in the M5 evaluation.
    */

    'linking' => [
        'v2' => (bool) env('COMMS_LINKING_V2', false),
        'surface_v2' => (bool) env('COMMS_SURFACE_V2', false),
        'reconciler' => (bool) env('COMMS_RECONCILER', false),
    ],

    'settle' => ['quiet_minutes' => 5, 'quiet_minutes_direct' => 2, 'max_minutes' => 20],

    'edit' => ['reprocess_hours' => 24],

    'window' => ['lookback_messages' => 30, 'lookback_hours' => 48],

    'agreement' => ['what_similarity' => 0.85],

    'examples' => ['max' => 5],

    'backfill' => ['days' => 30, 'eval_split_day' => 21, 'label_reply_days' => 3, 'ask_pattern_min' => 20, 'topic_batch_chars' => 60_000],

    'reconcile' => ['time' => '03:00', 'auto' => ['confirm_link', 'relink_uncertain', 'settle_quoted']],

    'embeddings' => [
        'provider' => 'gemini',
        'model' => env('GEMINI_EMBED_MODEL', 'gemini-embedding-001'),
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
        'topics_max' => 10,
        'intents_max' => 10,
        'settled_window_days' => 14,
        'duplicate_similarity' => 92,
        'duplicate_similarity_same_channel' => 78,
        'duplicate_cosine' => 0.92,
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
    | Sender fragments that are always noise in Comms (no dedicated connector).
    | Matched against the author email or display name.
    */

    'noise_from' => [
        '@m.personio.com',
        '@personio.com',
        'personio team',
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
        // Measured in M5; logged, not enforced, until then.
        'per_window' => 150_000,
        'enforce_window' => false,
    ],

];
