<?php

namespace App\Comms\Gate;

use App\Models\Comms\CommsPolicy;
use App\Models\User;

/**
 * The policies screen: one choice per action, plus the denies that never lift.
 */
class PolicyCatalog
{
    /**
     * @var array<string, string>
     */
    private const LABELS = [
        'send_slack' => 'Enviar un mensaje de Slack',
        'send_email_reply' => 'Contestar un email',
        'send_email_new' => 'Escribir un email nuevo',
        'jira_comment' => 'Comentar en Jira',
        'notion_comment' => 'Comentar en Notion',
        'calendar_create' => 'Crear un evento',
        'calendar_update' => 'Cambiar un evento',
        'notion_create_page' => 'Crear una página de Notion',
        'drive_share' => 'Compartir un archivo',
        'jira_update' => 'Actualizar un ticket',
        'notion_edit' => 'Editar una página de Notion',
    ];

    /**
     * @var list<array{reason: string, label: string, locked: true}>
     */
    private const DENIES = [
        ['reason' => 'sensitive', 'label' => 'Un mensaje sensible', 'locked' => true],
        ['reason' => 'client', 'label' => 'Un cliente, si no lo empezaste tú', 'locked' => true],
        ['reason' => 'verification', 'label' => 'Un dato del producto que no está verificado', 'locked' => true],
        ['reason' => 'date', 'label' => 'Una fecha que no has confirmado', 'locked' => true],
        ['reason' => 'target', 'label' => 'Un destinatario que no conocemos', 'locked' => true],
        ['reason' => 'allows', 'label' => 'Una acción que este borrador no permite', 'locked' => true],
    ];

    /**
     * @return array{
     *     classes: list<array{action_class: string, label: string, outcome: string, exceptions: list<array{context: string, outcome: string}>}>,
     *     denies: list<array{reason: string, label: string, locked: true}>
     * }
     */
    public function for(User $user): array
    {
        $rows = CommsPolicy::query()->whereBelongsTo($user)->orderBy('id')->get();
        $classes = [];

        foreach (ActionClass::cases() as $case) {
            $blanket = null;
            $exceptions = [];

            foreach ($rows as $row) {
                if ($row->action_class !== $case) {
                    continue;
                }

                $conditions = is_array($row->conditions) ? $row->conditions : [];

                if ($conditions === []) {
                    $blanket ??= $row->outcome->value;

                    continue;
                }

                $exceptions[] = [
                    'context' => $this->context($conditions),
                    'outcome' => $row->outcome->value,
                ];
            }

            $classes[] = [
                'action_class' => $case->value,
                'label' => self::LABELS[$case->value] ?? $case->value,
                'outcome' => $blanket ?? PolicyOutcome::Ask->value,
                'exceptions' => $exceptions,
            ];
        }

        return [
            'classes' => $classes,
            'denies' => self::DENIES,
        ];
    }

    /**
     * @param  array<string, mixed>  $conditions
     */
    private function context(array $conditions): string
    {
        $parts = [];

        foreach ($conditions as $key => $value) {
            if (! is_string($key) || (! is_string($value) && ! is_int($value))) {
                continue;
            }

            $parts[] = $key === 'channel' ? 'Canal '.$value : $key.': '.$value;
        }

        return implode(', ', $parts);
    }
}
