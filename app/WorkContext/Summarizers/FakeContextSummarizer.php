<?php

namespace App\WorkContext\Summarizers;

use App\Connectors\Chat\ChatMessage;
use App\Models\User;
use App\WorkContext\ContextSummarizer;
use App\WorkContext\WorkActivity;
use App\WorkContext\WorkDigest;

/**
 * For tests and demo mode: one workstream per document or page read, and
 * a summary that says how much was read.
 */
class FakeContextSummarizer implements ContextSummarizer
{
    /** @var list<WorkActivity> */
    public array $summarized = [];

    public function summarize(User $user, WorkActivity $activity): WorkDigest
    {
        $this->summarized[] = $activity;
        $counts = $activity->counts();

        $titles = [
            ...array_map(fn (array $item): string => $item['document']->title, $activity->documents),
            ...array_map(fn (array $item): string => $item['page']->title, $activity->pages),
        ];

        return new WorkDigest(
            summary: "Estos días {$user->name} ha escrito o recibido {$counts['slack']} mensajes de Slack y {$counts['gmail']} emails, y ha trabajado en ".count($titles).' documentos.',
            workstreams: array_map(fn (string $title): array => ['name' => $title, 'what' => 'Editado estos días', 'why' => null, 'with' => [], 'where' => [$title], 'next' => null], array_slice($titles, 0, WorkDigest::MAX_WORKSTREAMS)),
            people: array_values(array_map(fn (string $name): array => ['name' => $name, 'relation' => 'Habla con frecuencia'], array_slice(array_unique(array_map(
                fn (ChatMessage $message): string => $message->authorName,
                array_filter($activity->messages, fn (ChatMessage $message): bool => ! $message->isMine),
            )), 0, WorkDigest::MAX_PEOPLE))),
        );
    }
}
