<?php

namespace App\Voice\Tools;

/**
 * Something to show on screen: a registered canvas and the data it renders.
 */
final readonly class Canvas
{
    /**
     * @param  string  $view  Registered canvas name, e.g. "calendar.day".
     * @param  array<string, mixed>  $props
     * @param  'focus'|'wide'  $layout  A centred column, or the full width for large content.
     */
    public function __construct(
        public string $view,
        public array $props,
        public string $layout = 'focus',
    ) {}

    /**
     * @return array{view: string, props: array<string, mixed>, layout: string}
     */
    public function toArray(): array
    {
        return ['view' => $this->view, 'props' => $this->props, 'layout' => $this->layout];
    }
}
