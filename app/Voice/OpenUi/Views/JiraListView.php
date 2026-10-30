<?php

namespace App\Voice\OpenUi\Views;

use App\Connectors\Issues\IssueSummary;
use App\Models\User;
use App\Voice\OpenUi\ViewBuilder;
use App\Voice\Tools\Canvas;

/**
 * Jira issues, numbered: key and type, summary, status, priority and who
 * has it. A click opens the issue.
 */
class JiraListView
{
    /**
     * @param  list<IssueSummary>  $issues
     */
    public static function canvas(User $user, string $title, ?string $jql, array $issues): Canvas
    {
        $view = new ViewBuilder($title);

        if (filled($jql)) {
            $view->add('Text', [$jql, 'muted']);
        }

        $items = [];

        foreach ($issues as $index => $issue) {
            $n = $index + 1;

            $items[] = [
                'n' => $n,
                'title' => $issue->summary,
                'eyebrow' => "{$issue->key} · {$issue->type}",
                'status' => $issue->status,
                'meta' => implode(' · ', array_filter([$issue->priority, $issue->assignee])) ?: null,
            ];

            $view->openable($n, "{$issue->key} {$issue->summary}", 'open_jira_issue', ['issue' => $issue->ref]);
        }

        $view->add('ItemList', [$items, 'No hay issues que encajen.']);

        return $view->canvas($user, 'Jira');
    }
}
