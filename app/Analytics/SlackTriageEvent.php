<?php

namespace App\Analytics;

/**
 * Events the Slack triage is allowed to send. The name is the event. The
 * properties are a fixed set: message text, names, links and reasons never
 * leave the application.
 */
enum SlackTriageEvent: string
{
    case AttentionProfileBuilt = 'slack_attention_profile_built';
    case TriageDecided = 'slack_triage_decided';
    case TriageMissed = 'slack_triage_missed';
    case DailyDigestRead = 'slack_daily_digest_read';
    case ReplyPlanned = 'slack_reply_planned';
    case TriageFeedback = 'slack_triage_feedback';
    case PreferenceRuleProposed = 'slack_preference_rule_proposed';
    case PreferenceRuleAccepted = 'slack_preference_rule_accepted';
    case DraftConfirmed = 'slack_draft_confirmed';
    case AutonomyPromoted = 'slack_autonomy_promoted';

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, bool|int|string>
     */
    public static function properties(array $properties): array
    {
        $allowed = [];

        foreach ($properties as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $clean = self::property($key, $value);

            if ($clean !== null) {
                $allowed[$key] = $clean;
            }
        }

        return $allowed;
    }

    private static function property(string $key, mixed $value): bool|int|string|null
    {
        $choices = [
            'action' => ['reply', 'redirect', 'task', 'notice', 'ignore'],
            'place' => ['dm', 'group', 'mention', 'thread', 'channel'],
            'owner' => ['self', 'other', 'unknown'],
            'signal' => ['re_ping', 're_mention'],
            'correction' => ['not_important', 'someone_else', 'always_notify', 'right'],
            'edited' => ['none', 'light', 'rewritten'],
            'autonomy' => ['propose', 'auto'],
            'level' => ['watch', 'draft', 'act'],
        ];

        if (isset($choices[$key])) {
            return is_string($value) && in_array($value, $choices[$key], true) ? $value : null;
        }

        if (in_array($key, ['uncertain', 'already_answered', 'has_target', 'policy_present', 'sufficient', 'agent_asked', 'conflict'], true)) {
            return is_bool($value) ? $value : null;
        }

        if (in_array($key, ['threads', 'replied', 'missed', 'deprioritized_count'], true)) {
            return is_int($value) ? $value : null;
        }

        return null;
    }
}
