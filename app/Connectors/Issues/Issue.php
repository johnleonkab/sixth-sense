<?php

namespace App\Connectors\Issues;

/**
 * One issue whole: its fields, description, comments, children and dependencies.
 */
final readonly class Issue
{
    /**
     * @param  list<array<string, mixed>>  $description  Blocks, to render it.
     * @param  list<string>  $labels
     * @param  ?array{key: string, summary: string, type: string}  $parent  Its epic (or story, for a subtask).
     * @param  list<array{key: string, summary: string, status: string}>  $subtasks
     * @param  list<array{id: string, ref: string, relation: string, key: string, summary: string, status: string}>  $links  Dependencies and other links.
     * @param  list<IssueComment>  $comments  Oldest first.
     */
    public function __construct(
        public IssueSummary $summary,
        public array $description,
        public string $descriptionText,
        public ?string $reporter,
        public array $labels,
        public ?array $parent,
        public array $subtasks,
        public array $links,
        public array $comments,
    ) {}
}
