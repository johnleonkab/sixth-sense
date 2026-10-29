<?php

namespace App\Voice\Tools\Slack;

use App\Alerts\Intents\SlackPreference;
use App\Models\PendingAction;
use App\Models\SlackTriageDecision;
use App\Models\User;
use App\Voice\Tools\Canvas;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * One of the three taps. It proposes a rule and waits for "sí".
 */
class CorrectSlackTriage implements VoiceTool
{
    public function __construct(private SlackPreference $preferences) {}

    public function name(): string
    {
        return 'correct_slack_triage';
    }

    public function description(): string
    {
        return 'Corrige cómo se ha tratado un mensaje de Slack. correction es not_important ("no hace falta"), '
            .'someone_else ("le toca a otro") o always_notify ("avísame siempre"). '
            .'Pasa alert_id si está en un aviso, o decision_id si está en el resumen de lo dejado pasar. '
            .'La regla se enseña y espera el sí; no cambia nada hasta entonces.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'correction' => [
                    'type' => 'string',
                    'enum' => ['not_important', 'someone_else', 'always_notify'],
                    'description' => 'not_important, someone_else o always_notify.',
                ],
                'alert_id' => ['type' => 'integer', 'description' => 'Aviso de Slack que se corrige.'],
                'decision_id' => ['type' => 'integer', 'description' => 'Fila del resumen de lo dejado pasar.'],
            ],
            'required' => ['correction'],
        ];
    }

    public function rules(): array
    {
        return [
            'correction' => ['required', 'in:not_important,someone_else,always_notify'],
            'alert_id' => ['required_without:decision_id', 'integer'],
            'decision_id' => ['required_without:alert_id', 'integer'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $decision = $this->decision($user, $arguments);

        abort_if($decision === null, 404);

        $rule = $this->preferences->propose($user, $decision, $arguments['correction']);
        $action = PendingAction::query()->find($rule->pending_action_id);

        return new ToolResult(
            output: [
                'pending_action_id' => $action?->id,
                'rule' => $rule->body,
                'needs_confirmation' => true,
            ],
            canvas: new Canvas('slack.rule', [
                'title' => 'Regla de atención',
                'body' => $rule->body,
                'saved' => false,
                'action' => $action?->toState(),
            ]),
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function decision(User $user, array $arguments): ?SlackTriageDecision
    {
        $query = $user->slackTriageDecisions();

        if (isset($arguments['decision_id'])) {
            $query->whereKey($arguments['decision_id']);
        }

        if (isset($arguments['alert_id'])) {
            $query->where('inbox_item_id', $arguments['alert_id']);
        }

        return $query->first();
    }
}
