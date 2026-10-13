<?php

namespace App\Voice\Tools\Jira;

use App\Actions\Actions;
use App\Connectors\Issues\Issue;
use App\Connectors\Issues\IssueTracker;
use App\Models\User;
use App\Voice\Tools\Docs\ProposesChanges;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Shared steps for the Jira tools: find the issue, the site and the people
 * named, asking when it is unclear.
 */
trait UsesIssues
{
    use ProposesChanges;

    public function __construct(
        private IssueTracker $issues,
        private Actions $actions,
    ) {}

    /**
     * @throws RuntimeException with a message for the assistant.
     */
    private function issueNamed(User $user, string $reference): Issue
    {
        return $this->issues->issue($user, $reference)
            ?? throw new RuntimeException("No encuentro el issue «{$reference}». Búscalo con search_jira para tener su issue_id.");
    }

    /**
     * The site to use: the one named (its site_id or name), or the only one.
     *
     * @throws RuntimeException
     */
    private function siteFor(User $user, ?string $site): int
    {
        $sites = $this->issues->rules($user);

        if ($sites === []) {
            throw new RuntimeException('El usuario no tiene ninguna cuenta de Jira conectada. Puede conectarla en Configuración (Conexiones).');
        }

        if (blank($site)) {
            if (count($sites) > 1) {
                throw new RuntimeException('Tiene varios Jira conectados: '.implode(', ', array_column($sites, 'site')).'. Pregúntale en cuál.');
            }

            return $sites[0]['account_id'];
        }

        $match = collect($sites)->first(fn (array $candidate): bool => (string) $candidate['account_id'] === $site || Str::contains(Str::lower($candidate['site']), Str::lower($site)));

        return $match['account_id'] ?? throw new RuntimeException("No tiene conectado ningún Jira «{$site}».");
    }

    /**
     * People named, as Jira knows them: display name => account id.
     *
     * @param  list<string>  $names
     * @return array<string, string>
     *
     * @throws RuntimeException
     */
    private function peopleNamed(User $user, int $accountId, array $names): array
    {
        $people = [];

        foreach ($names as $name) {
            $found = $this->issues->people($user, $accountId, ltrim($name, '@'));

            if ($found === []) {
                throw new RuntimeException("No encuentro a «{$name}» en Jira. Pregúntale el nombre completo.");
            }

            $exact = array_values(array_filter($found, fn (array $person): bool => Str::lower(Str::ascii($person['name'])) === Str::lower(Str::ascii(ltrim($name, '@')))));
            $person = $exact[0] ?? (count($found) === 1 ? $found[0] : throw new RuntimeException("Hay varias personas que encajan con «{$name}»: ".implode(', ', array_column($found, 'name')).'. Pregúntale cuál.'));
            $people[$person['name']] = $person['id'];
        }

        return $people;
    }

    private static function siteOf(string $ref): int
    {
        return (int) Str::before($ref, ':');
    }
}
