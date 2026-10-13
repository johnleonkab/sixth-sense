<?php

namespace App\Connectors\Issues;

use App\Connections\Capability;
use App\Connectors\Jira\JiraRules;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * A small Jira project in the spirit of docs/prototype.html, for tests and
 * demo mode. What is written is kept in memory.
 */
class FakeIssueTracker implements IssueTracker
{
    /** @var list<array{0: string, 1: mixed}> */
    public array $writes = [];

    public function search(User $user, ?string $jql, ?string $text = null, int $limit = 15): array
    {
        return array_slice(array_values(array_filter(
            array_map(fn (array $issue): IssueSummary => $this->summary($issue), $this->issues()),
            fn (IssueSummary $issue): bool => blank($text) || Str::contains(Str::lower(Str::ascii($issue->summary.' '.$issue->key)), Str::lower(Str::ascii($text))),
        )), 0, $limit);
    }

    public function issue(User $user, string $reference): ?Issue
    {
        preg_match('/[A-Z]+-\d+/', $reference, $match);
        $raw = collect($this->issues())->firstWhere('key', $match[0] ?? '');

        if ($raw === null) {
            return null;
        }

        $comments = $raw['key'] === 'CPD-412' ? [
            new IssueComment('demo:CPD-412:10001', '10001', 'Marta Ruiz', 'u-marta', false, '¿Cerramos el alcance del reembolso parcial antes del sprint?', [['type' => 'paragraph', 'runs' => [['text' => '¿Cerramos el alcance del reembolso parcial antes del sprint?']]]], CarbonImmutable::today()->setTime(9, 5)),
            new IssueComment('demo:CPD-412:10002', '10002', 'Pablo Gil', 'u-pablo', false, '@John ¿nos ayudas a decidir si entra en este sprint?', [['type' => 'paragraph', 'runs' => [['text' => '@John', 'bold' => true], ['text' => ' ¿nos ayudas a decidir si entra en este sprint?']]]], CarbonImmutable::today()->setTime(9, 40), mentionsMe: true),
        ] : [];

        return new Issue(
            summary: $this->summary($raw),
            description: [['type' => 'paragraph', 'runs' => [['text' => $raw['description']]]]],
            descriptionText: $raw['description'],
            reporter: 'Marta Ruiz',
            labels: ['prepago'],
            parent: $raw['key'] === 'CPD-412' ? ['key' => 'CPD-400', 'summary' => 'Pagar la reserva por adelantado', 'type' => 'Epic'] : null,
            subtasks: [],
            links: $raw['key'] === 'CPD-412' ? [['id' => '501', 'ref' => 'demo:501', 'relation' => 'is blocked by', 'key' => 'CPD-405', 'summary' => 'Integrar la pasarela de pago', 'status' => 'In Progress']] : [],
            comments: $comments,
        );
    }

    public function allows(User $user, string $ref, Capability $capability): bool
    {
        return str_starts_with($ref, 'demo:');
    }

    public function rules(User $user): array
    {
        return [['account_id' => 0, 'site' => 'covermanager', 'rules' => new JiraRules(JiraRules::template())]];
    }

    public function people(User $user, int $accountId, string $query): array
    {
        return array_values(array_filter([
            ['id' => 'u-marta', 'name' => 'Marta Ruiz', 'email' => 'marta@covermanager.test'],
            ['id' => 'u-pablo', 'name' => 'Pablo Gil', 'email' => 'pablo@covermanager.test'],
        ], fn (array $person): bool => Str::contains(Str::lower(Str::ascii($person['name'])), Str::lower(Str::ascii($query)))));
    }

    public function priorities(User $user, int $accountId): array
    {
        return ['Highest', 'High', 'Medium', 'Low', 'Lowest'];
    }

    public function issueTypes(User $user, int $accountId, string $projectKey): array
    {
        return [['name' => 'Epic', 'subtask' => false], ['name' => 'Story', 'subtask' => false], ['name' => 'Task', 'subtask' => false], ['name' => 'Subtask', 'subtask' => true]];
    }

    public function create(User $user, int $accountId, NewIssue $issue): IssueSummary
    {
        $this->writes[] = ['create', $issue->toArray()];
        $key = $issue->projectKey.'-'.(500 + count($this->writes));

        return new IssueSummary("demo:{$key}", $key, $issue->summary, $issue->type, 'To Do', 'new', $issue->priority, null, CarbonImmutable::now(), "https://covermanager.atlassian.net/browse/{$key}", 'covermanager');
    }

    public function update(User $user, string $ref, IssueChanges $changes): void
    {
        $this->writes[] = ['update', ['ref' => $ref, ...$changes->toArray()]];
    }

    public function link(User $user, string $blockerRef, string $blockedRef): void
    {
        $this->writes[] = ['link', ['blocker' => $blockerRef, 'blocked' => $blockedRef]];
    }

    public function unlink(User $user, string $linkRef): void
    {
        $this->writes[] = ['unlink', ['link' => $linkRef]];
    }

    public function comment(User $user, string $ref, string $markdown, array $mentions = []): string
    {
        $this->writes[] = ['comment', ['ref' => $ref, 'markdown' => $markdown, 'mentions' => $mentions]];

        return 'comment-'.count($this->writes);
    }

    /** @var list<string> Comments the user has answered, for tests. */
    public array $answered = [];

    public function repliedAfter(User $user, string $commentRef): bool
    {
        return in_array($commentRef, $this->answered, true);
    }

    /**
     * @param  array<string, string>  $issue
     */
    private function summary(array $issue): IssueSummary
    {
        return new IssueSummary("demo:{$issue['key']}", $issue['key'], $issue['summary'], $issue['type'], $issue['status'], $issue['category'], $issue['priority'], $issue['assignee'], CarbonImmutable::today()->setTime(10, 0), "https://covermanager.atlassian.net/browse/{$issue['key']}", 'covermanager');
    }

    /**
     * @return list<array<string, string>>
     */
    private function issues(): array
    {
        return [
            ['key' => 'CPD-400', 'summary' => 'Pagar la reserva por adelantado', 'type' => 'Epic', 'status' => 'In Progress', 'category' => 'indeterminate', 'priority' => 'High', 'assignee' => 'Marta Ruiz', 'description' => 'Reducir los no-shows cobrando por adelantado.'],
            ['key' => 'CPD-405', 'summary' => 'Integrar la pasarela de pago', 'type' => 'Story', 'status' => 'In Progress', 'category' => 'indeterminate', 'priority' => 'High', 'assignee' => 'Pablo Gil', 'description' => 'Stripe para el cobro.'],
            ['key' => 'CPD-412', 'summary' => 'As a diner, I want to see the total before choosing a table', 'type' => 'Story', 'status' => 'To Do', 'category' => 'new', 'priority' => 'Medium', 'assignee' => 'John León', 'description' => 'Show the total amount in step 3.'],
        ];
    }
}
