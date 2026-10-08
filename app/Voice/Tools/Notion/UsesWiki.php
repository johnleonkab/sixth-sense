<?php

namespace App\Voice\Tools\Notion;

use App\Actions\Actions;
use App\Connectors\Wiki\Wiki;
use App\Connectors\Wiki\WikiPage;
use App\Connectors\Wiki\WikiPageSummary;
use App\Models\User;
use App\Voice\Tools\Docs\ProposesChanges;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Shared steps for the Notion tools: turn what the person said (an id, a
 * link, a title) into a page, asking when it is unclear.
 */
trait UsesWiki
{
    use ProposesChanges;

    public function __construct(
        private Wiki $wiki,
        private Actions $actions,
    ) {}

    /**
     * @throws RuntimeException with a message for the assistant.
     */
    private function pageNamed(User $user, string $reference): WikiPage
    {
        $page = $this->wiki->page($user, $reference);

        if ($page !== null) {
            return $page;
        }

        $matches = $this->wiki->search($user, $reference, 5);
        $exact = array_values(array_filter($matches, fn (WikiPageSummary $match): bool => Str::lower($match->title) === Str::lower(trim($reference))));
        $chosen = $exact[0] ?? (count($matches) === 1 ? $matches[0] : null);

        if ($chosen !== null) {
            return $this->wiki->page($user, $chosen->ref) ?? throw new RuntimeException("No puedo abrir «{$chosen->title}».");
        }

        if ($matches === []) {
            throw new RuntimeException("No encuentro ninguna página de Notion que sea «{$reference}». Puede que no se compartiera con sixth-sense al conectar Notion.");
        }

        throw new RuntimeException('Hay varias páginas que encajan: '.implode(', ', array_map(fn (WikiPageSummary $match): string => "«{$match->title}»", $matches)).'. Pregúntale cuál.');
    }
}
