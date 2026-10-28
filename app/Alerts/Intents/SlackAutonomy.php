<?php

namespace App\Alerts\Intents;

use App\Actions\Actions;
use App\Analytics\ProductAnalytics;
use App\Analytics\SlackTriageEvent;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\OutgoingChatMessage;
use App\Models\PendingAction;
use App\Models\SlackTriageDecision;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The autonomy ladder for Slack. A category rises only after the measured
 * sample clears the bar and the person says yes. Lowering applies at once.
 */
class SlackAutonomy
{
    public function __construct(
        private Actions $actions,
        private Chats $chats,
        private ProductAnalytics $analytics,
    ) {}

    public function level(User $user, SlackAction $action): AutonomyLevel
    {
        return $user->slackAutonomyLevels()->where('action', $action)->first()?->level ?? AutonomyLevel::Draft;
    }

    public function lower(User $user, SlackAction $action, AutonomyLevel $level): AutonomyLevel
    {
        $current = $this->level($user, $action);

        if ($level->rank() >= $current->rank()) {
            throw new RuntimeException('Bajar es hacia un nivel más bajo.');
        }

        $user->slackAutonomyLevels()->updateOrCreate(
            ['action' => $action],
            ['level' => $level],
        );

        return $level;
    }

    /**
     * The next level up, when the sample allows it. Act is the only step
     * with a measured bar. Returning to draft does not need one.
     */
    public function offer(User $user, SlackAction $action): ?PendingAction
    {
        $target = $this->target($user, $action);

        if ($target === null) {
            return null;
        }

        return $this->actions->propose($user, 'slack.autonomy', $this->label($action, $target), [
            'action' => $action->value,
            'level' => $target->value,
        ]);
    }

    public function promote(User $user, SlackAction $action, AutonomyLevel $level): AutonomyLevel
    {
        $current = $this->level($user, $action);

        if ($level->rank() <= $current->rank()) {
            throw new RuntimeException('Esa categoría ya está en ese nivel.');
        }

        if ($level === AutonomyLevel::Act && ! $this->eligible($user, $action)) {
            throw new RuntimeException($this->blocked($user, $action));
        }

        $user->slackAutonomyLevels()->updateOrCreate(
            ['action' => $action],
            ['level' => $level],
        );

        $this->analytics->capture($user, SlackTriageEvent::AutonomyPromoted, [
            'action' => $action->value,
            'level' => $level->value,
        ]);

        return $level;
    }

    public function eligible(User $user, SlackAction $action): bool
    {
        return match ($action) {
            SlackAction::Redirect => $this->redirectReady($user),
            SlackAction::Reply => $this->replyReady($user),
            default => false,
        };
    }

    public function blocked(User $user, SlackAction $action): string
    {
        if ($this->level($user, $action) === AutonomyLevel::Act) {
            return 'Esa categoría ya actúa sola.';
        }

        if ($action === SlackAction::Task) {
            return 'Las tareas se quedan en proponer: no hay un umbral para enviarlas solas.';
        }

        if ($action === SlackAction::Redirect) {
            $decided = $this->count($user, SlackAction::Redirect);
            $accepted = $this->count($user, SlackAction::Redirect, confirmed: true);

            return "Para enviar una redirección sola hacen falta al menos 30 propuestas y aceptar el 70 %. Llevas {$accepted} de {$decided}.";
        }

        $confirmed = $this->count($user, SlackAction::Reply, confirmed: true);
        $kept = $this->kept($user);

        return "Para enviar una respuesta sola hacen falta al menos 20 confirmaciones y que el 60 % no se reescriba. Llevas {$kept} de {$confirmed}.";
    }

    /**
     * A confirmed Slack send. The text stays here; PostHog gets the distance.
     */
    public function note(PendingAction $action): void
    {
        $id = $action->payload['slack_decision_id'] ?? null;

        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return;
        }

        $decision = SlackTriageDecision::query()->where('user_id', $action->user_id)->find((int) $id);

        if ($decision === null || $decision->confirmed_at !== null) {
            return;
        }

        $sent = (string) ($action->payload['message']['text'] ?? '');
        $original = (string) ($action->payload['proposed_text'] ?? $decision->draft_text ?? $sent);
        $autonomy = $action->payload['autonomy'] ?? 'propose';

        $decision->update([
            'confirmed_at' => now(),
            'edited' => self::edited($original, $sent),
            'autonomy' => $autonomy === 'auto' ? 'auto' : 'propose',
        ]);

        $this->analytics->capture($decision->user, SlackTriageEvent::DraftConfirmed, [
            'action' => $decision->action,
            'edited' => $decision->edited,
            'autonomy' => $decision->autonomy,
        ]);
    }

    /**
     * Act sends the draft and leaves the undo window. A missing fact stays a proposal.
     */
    public function perform(User $user, SlackTriageDecision $decision, string $text): void
    {
        $text = trim($text);

        if ($decision->confirmed_at !== null || $text === '') {
            return;
        }

        $to = Str::beforeLast($decision->external_id, ':');

        if ($to === '' || $to === $decision->external_id || ! $this->chats->canSend($user, $to)) {
            return;
        }

        $message = new OutgoingChatMessage(
            $to,
            $decision->channel ?? 'Slack',
            $text,
            [],
            $decision->place === 'dm' ? null : $decision->thread_key,
        );

        $pending = $this->actions->propose($user, 'chat.send', 'Enviar en '.($decision->channel ?? 'Slack'), [
            'message' => $message->toArray(),
            'slack_decision_id' => $decision->id,
            'proposed_text' => $text,
            'autonomy' => 'auto',
        ]);

        $this->actions->confirm($pending);
    }

    /**
     * @return array{levels: list<array<string, mixed>>, board: list<array{label: string, value: string}>}
     */
    public function present(User $user): array
    {
        $levels = [];

        foreach ([SlackAction::Reply, SlackAction::Redirect, SlackAction::Task] as $action) {
            $current = $this->level($user, $action);
            $target = $this->target($user, $action);

            $levels[] = [
                'action' => $action->value,
                'label' => $action->label(),
                'level' => $current->value,
                'level_label' => $current->label(),
                'can_lower' => $current !== AutonomyLevel::Watch,
                'lower_level' => $current === AutonomyLevel::Act ? AutonomyLevel::Draft->value : AutonomyLevel::Watch->value,
                'can_raise' => $target !== null,
                'raise_reason' => $target === null && $current !== AutonomyLevel::Act ? $this->blocked($user, $action) : null,
            ];
        }

        return [
            'levels' => $levels,
            'board' => $this->board($user),
        ];
    }

    public static function edited(string $original, string $sent): string
    {
        $before = self::words($original);
        $after = self::words($sent);

        if ($before === $after) {
            return 'none';
        }

        if ($before === [] || $after === []) {
            return 'rewritten';
        }

        $shared = count(array_intersect($before, $after));

        return $shared / max(count($before), count($after)) >= 0.6 ? 'light' : 'rewritten';
    }

    private function target(User $user, SlackAction $action): ?AutonomyLevel
    {
        $current = $this->level($user, $action);

        if ($current === AutonomyLevel::Watch) {
            return AutonomyLevel::Draft;
        }

        if ($current === AutonomyLevel::Draft && $this->eligible($user, $action)) {
            return AutonomyLevel::Act;
        }

        return null;
    }

    private function redirectReady(User $user): bool
    {
        $decided = $this->count($user, SlackAction::Redirect);

        if ($decided < 30) {
            return false;
        }

        return $this->count($user, SlackAction::Redirect, confirmed: true) / $decided >= 0.70;
    }

    private function replyReady(User $user): bool
    {
        $confirmed = $this->count($user, SlackAction::Reply, confirmed: true);

        if ($confirmed < 20) {
            return false;
        }

        return $this->kept($user) / $confirmed >= 0.60;
    }

    private function count(User $user, SlackAction $action, bool $confirmed = false): int
    {
        return $user->slackTriageDecisions()
            ->where('action', $action)
            ->when($confirmed, fn ($query) => $query->whereNotNull('confirmed_at'))
            ->count();
    }

    private function kept(User $user): int
    {
        return $user->slackTriageDecisions()
            ->where('action', SlackAction::Reply)
            ->whereNotNull('confirmed_at')
            ->where('edited', '!=', 'rewritten')
            ->count();
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function board(User $user): array
    {
        $replies = $this->count($user, SlackAction::Reply);
        $missed = $user->slackTriageDecisions()->whereNotNull('missed_at')->count();
        $redirects = $this->count($user, SlackAction::Redirect);
        $redirectsAccepted = $this->count($user, SlackAction::Redirect, confirmed: true);
        $replyConfirmed = $this->count($user, SlackAction::Reply, confirmed: true);
        $kept = $this->kept($user);
        $always = $user->slackPreferenceRules()->where('correction', 'always_notify')->count();
        $precise = $user->slackTriageDecisions()
            ->where('action', SlackAction::Reply)
            ->whereNotNull('confirmed_at')
            ->whereDoesntHave('preferenceRules', fn ($query) => $query->where('correction', 'not_important'))
            ->count();

        return [
            ['label' => 'Se me escapó', 'value' => self::percent($missed, $replies + $missed)],
            ['label' => 'Precisión en «responde tú»', 'value' => self::percent($precise, $replies)],
            ['label' => 'Recall de «responde tú»', 'value' => self::percent($precise, $replies + $missed + $always)],
            ['label' => 'Redirecciones aceptadas', 'value' => self::percent($redirectsAccepted, $redirects)],
            ['label' => 'Borradores que no se reescriben', 'value' => self::percent($kept, $replyConfirmed)],
            ['label' => 'Horas hasta confirmar un «responde tú»', 'value' => $this->hours($user)],
        ];
    }

    private function hours(User $user): string
    {
        $spans = $user->slackTriageDecisions()
            ->where('action', SlackAction::Reply)
            ->whereNotNull('confirmed_at')
            ->get(['created_at', 'confirmed_at'])
            ->map(fn (SlackTriageDecision $decision): float => $decision->created_at->diffInHours($decision->confirmed_at, true))
            ->sort()
            ->values();

        if ($spans->isEmpty()) {
            return 'Aún no hay muestra';
        }

        $middle = $spans[(int) floor(($spans->count() - 1) / 2)];

        return rtrim(rtrim(number_format($middle, 1, ',', ''), '0'), ',').' h';
    }

    private static function percent(int $part, int $whole): string
    {
        if ($whole === 0) {
            return 'Aún no hay muestra';
        }

        return (int) round($part / $whole * 100).' %';
    }

    /**
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];

        return array_values(array_filter($parts, fn (string $word): bool => mb_strlen($word) >= 2));
    }

    private function label(SlackAction $action, AutonomyLevel $level): string
    {
        $name = $action->label();

        return $level === AutonomyLevel::Act
            ? "Subir «{$name}» a hacerlo sola. Se enviará y quedarán unos segundos para deshacer."
            : "Subir «{$name}» a proponer un borrador.";
    }
}
