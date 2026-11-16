<?php

namespace App\Comms\Nightly;

use App\Comms\Context\ContextEngine;
use App\Comms\Context\ContextWriter;
use App\Comms\Context\FactOrigin;
use App\Models\Comms\CommsReconcileChange;
use App\Models\User;

/**
 * The Cambios de la noche screen: the night's list, and which classes the
 * night may apply without asking.
 */
class NightChanges
{
    /** @var array<string, string> */
    public const CLASSES = [
        'confirm_link' => 'Confirmar el enlace',
        'relink_uncertain' => 'Mover un enlace dudoso',
        'settle_quoted' => 'Dar por cerrado',
        'merge' => 'Unir dos pendientes',
    ];

    public function __construct(
        private ContextWriter $writer,
        private ContextEngine $context,
        private ChangeList $changes,
    ) {}

    /**
     * @return array{lines: list<array{id: int, op: string, quote: ?string, why: ?string, disposition: string, answers: list<string>}>, classes: list<array{key: string, label: string, on: bool}>}
     */
    public function screen(User $user): array
    {
        $latest = CommsReconcileChange::query()->whereBelongsTo($user)->orderByDesc('run_date')->orderByDesc('id')->first();
        $lines = [];

        if ($latest !== null) {
            foreach (CommsReconcileChange::query()->whereBelongsTo($user)->whereDate('run_date', $latest->run_date)->orderBy('id')->get() as $change) {
                $lines[] = [
                    'id' => $change->id,
                    'op' => $change->op,
                    'quote' => $change->quote,
                    'why' => $change->why,
                    'disposition' => $change->disposition,
                    'answers' => ['igual', 'no es igual', 'deshacer'],
                ];
            }
        }

        $saved = $this->saved($user);
        $defaults = config('comms.reconcile.auto');
        $defaults = is_array($defaults) ? $defaults : [];
        $classes = [];

        foreach (self::CLASSES as $key => $label) {
            $classes[] = [
                'key' => $key,
                'label' => $label,
                'on' => $saved === null ? in_array($key, $defaults, true) : ($saved[$key] ?? false),
            ];
        }

        return [
            'lines' => $lines,
            'classes' => $classes,
        ];
    }

    /**
     * @param  array<string, bool>  $classes
     */
    public function save(User $user, array $classes): void
    {
        $operations = [];

        foreach (self::CLASSES as $key => $label) {
            $operations[] = [
                'op' => 'set',
                'kind' => 'policy',
                'subject' => 'reconcile:'.$key,
                'attribute' => 'apply_alone',
                'value' => ['on' => (bool) ($classes[$key] ?? false), 'class' => $key, 'text' => $label],
                'audiences' => [],
            ];
        }

        $this->writer->record($user, FactOrigin::User, $operations);
    }

    public function answer(User $user, CommsReconcileChange $change, string $answer): void
    {
        $this->changes->answer($user, $change, $answer);
    }

    /**
     * Null when the person has not saved the switches. Otherwise the saved
     * on/off of each class.
     *
     * @return array<string, bool>|null
     */
    private function saved(User $user): ?array
    {
        $facts = $this->context->listing($user, ['active'], ['policy'], ['apply_alone'], 20);

        if ($facts === []) {
            return null;
        }

        $saved = [];

        foreach ($facts as $fact) {
            $class = $fact['value']['class'] ?? null;

            if (! is_string($class) || ! array_key_exists($class, self::CLASSES)) {
                continue;
            }

            $saved[$class] = ($fact['value']['on'] ?? false) === true;
        }

        return $saved;
    }
}
