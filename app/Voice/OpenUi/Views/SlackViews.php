<?php

namespace App\Voice\OpenUi\Views;

use App\Alerts\Intents\AutonomyLevel;
use App\Models\PendingAction;
use App\Models\User;
use App\Voice\OpenUi\Dates;
use App\Voice\OpenUi\Ref;
use App\Voice\OpenUi\ViewBuilder;
use App\Voice\Tools\Canvas;
use Carbon\CarbonImmutable;

/**
 * How Slack is judged, as views: what it left aside, and how far it may go
 * on its own. Every button either proposes a change that waits for the "sí"
 * or makes it more careful.
 */
class SlackViews
{
    /** @var array<string, string> */
    private const CORRECTIONS = ['not_important' => 'No hace falta', 'someone_else' => 'Le toca a otro', 'always_notify' => 'Avísame siempre'];

    /**
     * What Slack left aside since yesterday, numbered, each with the three
     * corrections (each proposes a rule).
     *
     * @param  list<array{number: int, id: int, ref: string, action_label: string, place_label: string, who: string, excerpt: string, reason: string, at: string}>  $items
     */
    public static function digest(User $user, string $title, array $items, string $timezone): Canvas
    {
        $view = new ViewBuilder($title);
        $list = [];

        foreach ($items as $item) {
            $list[] = [
                'n' => $item['number'],
                'title' => $item['excerpt'],
                'eyebrow' => implode(' · ', [$item['who'], $item['place_label'], $item['action_label']]),
                'snippet' => $item['reason'],
                'meta' => Dates::relative(CarbonImmutable::parse($item['at']), $timezone),
                'ref' => "slack-message:{$item['ref']}",
                'actions' => array_map(
                    fn (string $correction, string $label): array => ['label' => $label, 'ref' => "correct:{$item['id']}:{$correction}"],
                    array_keys(self::CORRECTIONS),
                    self::CORRECTIONS,
                ),
            ];
            $view->openable($item['number'], $item['excerpt'], 'show_slack_conversation', ['message_id' => $item['ref']]);
        }

        $view->add('ItemList', [$list, 'Desde ayer no he dejado pasar ningún mensaje.']);

        return $view->canvas($user, 'Slack', 'focus');
    }

    /**
     * How far Slack may go for each kind of message, what each level means,
     * and how it has done. Raising waits for the "sí"; lowering applies at once.
     *
     * @param  array{levels: list<array<string, mixed>>, board: list<array{label: string, value: string}>}  $page
     */
    public static function autonomy(User $user, array $page, ?PendingAction $pending = null): Canvas
    {
        $view = new ViewBuilder('Autonomía de Slack');

        foreach ($page['levels'] as $row) {
            $buttons = [];

            if ($row['can_raise'] && $row['raise_level']) {
                $buttons[] = $view->add('Button', ['Subir a «'.AutonomyLevel::from($row['raise_level'])->label().'»', new Ref("@Do(\"autonomy-raise:{$row['action']}\")")], top: false);
            }

            if ($row['can_lower']) {
                $buttons[] = $view->add('Button', ['Bajar a «'.AutonomyLevel::from($row['lower_level'])->label().'»', new Ref("@Do(\"autonomy-lower:{$row['action']}:{$row['lower_level']}\")"), 'tertiary'], top: false);
            }

            $children = [
                $view->add('Text', ["{$row['level_label']}: {$row['explanation']}"], top: false),
                ...($row['raise_reason'] ? [$view->add('Text', [$row['raise_reason'], 'muted'], top: false)] : []),
                ...($buttons !== [] ? [$view->add('Buttons', [$buttons], top: false)] : []),
            ];

            $view->add('Card', [$row['label'], $children]);
        }

        foreach (array_chunk($page['board'], 3) as $metrics) {
            $view->add('Row', [array_map(fn (array $metric): Ref => $view->add('Metric', [$metric['label'], $metric['value']], top: false), $metrics)]);
        }

        if ($pending !== null) {
            $view->proposal($pending, 'subir', 'Nivel subido');
        }

        return $view->canvas($user, 'Slack', 'focus');
    }
}
