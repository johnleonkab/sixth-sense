<?php

namespace App\Voice\Tools;

final readonly class ToolResult
{
    /**
     * @param  array<string, mixed>  $output  What the model reads.
     * @param  ?Canvas  $canvas  What the screen shows, if anything.
     * @param  ?Canvas  $beside  A second canvas when the decision needs both, shown to the right.
     */
    public function __construct(
        public array $output,
        public ?Canvas $canvas = null,
        public ?Canvas $beside = null,
    ) {}

    public static function error(string $message): self
    {
        return new self(['error' => $message]);
    }

    /**
     * @return array{output: array<string, mixed>, canvas: ?array{view: string, props: array<string, mixed>, layout: string}, beside: ?array{view: string, props: array<string, mixed>, layout: string}}
     */
    public function toArray(): array
    {
        return [
            'output' => $this->output,
            'canvas' => $this->canvas?->toArray(),
            'beside' => $this->beside?->toArray(),
        ];
    }
}
