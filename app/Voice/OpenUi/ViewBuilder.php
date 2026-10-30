<?php

namespace App\Voice\OpenUi;

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
        OpenUi::rememberItems($user, $this->items);

        return new Canvas('openui.view', [
            'title' => $this->title,
            'source' => $source,
            'ui' => $this->toString(),
            'items' => $this->items,
        ], $layout);
    }

    /**
     * Add a block to the view (or keep it for a Row or Card, with $top false).
     *
     * @param  list<mixed>  $arguments
     */
    public function add(string $block, array $arguments, bool $top = true): Ref
    {
        $ref = new Ref(lcfirst($block).(++$this->count));
        $this->statements[] = "{$ref->name} = ".OpenUi::call($block, $arguments);

        if ($top) {
            $this->children[] = $ref;
        }

        return $ref;
    }

    public function toString(): string
    {
        return implode("\n", ['root = '.OpenUi::call('View', [$this->title, $this->children]), ...$this->statements]);
    }
}
