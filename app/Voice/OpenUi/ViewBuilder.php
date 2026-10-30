<?php

namespace App\Voice\OpenUi;

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

    private int $count = 0;

    public function __construct(private string $title) {}

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
