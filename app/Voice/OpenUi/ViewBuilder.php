<?php

namespace App\Voice\OpenUi;

use App\Models\PendingAction;
use App\Models\User;
use App\Voice\Tools\Canvas;

/**
 * Builds one view statement by statement, root first, so the program reads
 * the way OpenUI streams it best.
 */
class ViewBuilder
{
    /** @var list<string> */
    private array $statements = [];

    /** @var list<Ref> */
    private array $children = [];

    /** @var list<array{n: int, title: string, tool: string, args: array<string, mixed>}> */
    private array $items = [];

    private int $count = 0;

    public function __construct(private string $title) {}

    /**
     * Say how the item numbered n of the view's lists opens: on a click, and
     * when the person says "abre el n".
     *
     * @param  array<string, mixed>  $args
     */
    public function openable(int $n, string $title, string $tool, array $args): void
    {
        $this->items[] = ['n' => $n, 'title' => $title, 'tool' => $tool, 'args' => $args];
    }

    /**
     * The view on screen, with the items it can open (also remembered for a
     * version the assistant rewrites).
     */
    public function canvas(User $user, string $source, string $layout = 'wide'): Canvas
    {
        $view = ['title' => $this->title, 'source' => $source, 'ui' => $this->toString(), 'items' => $this->items];

        OpenUi::rememberItems($user, $this->items);
        OpenUi::rememberView($user, $view);

        return new Canvas('openui.view', $view, $layout);
    }

    /**
     * Add a block to the view (or keep it for a Row or Card, with $top false),
     * or only while $when holds.
     *
     * @param  list<mixed>  $arguments
     */
    public function add(string $block, array $arguments, bool $top = true, ?Ref $when = null): Ref
    {
        $ref = new Ref(lcfirst($block).(++$this->count));
        $call = OpenUi::call($block, $arguments);
        $this->statements[] = "{$ref->name} = ".($when ? "{$when->name} ? {$call} : null" : $call);

        if ($top) {
            $this->children[] = $ref;
        }

        return $ref;
    }

    /**
     * A change that waits for the "sí", with its bar: Sí and Cancelar, then
     * the seconds to undo.
     */
    public function proposal(PendingAction $action, string $verb = 'aplicar', ?string $done = null): Ref
    {
        return $this->add('Proposal', [$action->id, $verb, $done]);
    }

    /**
     * A $variable the view's tabs and buttons change, and its queries read.
     */
    public function variable(string $name, string|int $default): Ref
    {
        $this->statements[] = "\${$name} = ".OpenUi::value($default);

        return new Ref("\${$name}");
    }

    /**
     * The rows of a dataset, asked for while the view is on screen: again
     * when an argument's $variable changes, and every $refresh seconds.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function query(string $dataset, array $arguments, ?int $refresh = null): Ref
    {
        $ref = new Ref('query'.(++$this->count));
        $this->statements[] = "{$ref->name} = ".OpenUi::call('Query', [$dataset, $arguments, ['rows' => []], $refresh]);

        return $ref;
    }

    public function toString(): string
    {
        return implode("\n", ['root = '.OpenUi::call('View', [$this->title, $this->children]), ...$this->statements]);
    }
}
