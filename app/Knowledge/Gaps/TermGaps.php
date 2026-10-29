<?php

namespace App\Knowledge\Gaps;

use App\Knowledge\KnowledgeSource;
use App\Models\KnowledgeDocument;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Finds acronyms and project names that keep coming up in recent Jira,
 * Notion and meeting notes but that the work archive never explains. A
 * cheap count picks the candidates; the judge keeps the ones worth asking.
 */
class TermGaps
{
    /** Recent enough to matter now. */
    private const DAYS = 14;

    /** A term has to appear in at least this many documents. */
    private const MIN_DOCUMENTS = 3;

    /** Candidates the judge reads per look. */
    private const CANDIDATES = 8;

    /** Uppercase words every team uses: never asked. */
    private const COMMON = [
        'API', 'URL', 'PDF', 'CSV', 'JSON', 'HTML', 'CSS', 'SQL', 'SDK', 'CLI', 'MCP', 'LLM', 'GPT', 'IA', 'AI', 'ML', 'BI',
        'OK', 'KO', 'CEO', 'CTO', 'CFO', 'COO', 'CPO', 'VP', 'HR', 'RRHH', 'IT', 'UX', 'UI', 'QA', 'PR', 'PO', 'PM',
        'MVP', 'KPI', 'OKR', 'OKRS', 'ROI', 'SLA', 'ETA', 'FAQ', 'TBD', 'TODO', 'WIP', 'ID', 'CRM', 'ERP', 'B2B', 'B2C', 'SAAS',
        'EU', 'UE', 'USA', 'ES', 'EN', 'UK', 'CET', 'CEST', 'UTC', 'GMT', 'AM', 'PM', 'EUR', 'USD', 'IVA', 'NIF', 'CIF',
        'Q1', 'Q2', 'Q3', 'Q4', 'H1', 'H2', 'NO', 'SI', 'OR', 'AND', 'THE', 'FOR', 'APP', 'WEB', 'SMS', 'GDPR', 'RGPD',
        'SSO', 'OTP', 'VPN', 'DB', 'CI', 'CD', 'AWS', 'GCP', 'IOS', 'PNG', 'JPG', 'SVG', 'MP4', 'NA', 'N/A', 'FYI', 'ASAP',
    ];

    public function __construct(private TermJudge $judge) {}

    /**
     * @return int Questions noticed in this look.
     */
    public function find(User $user, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $counts = [];
        $examples = [];

        $user->knowledgeDocuments()
            ->whereIn('source', [KnowledgeSource::Jira, KnowledgeSource::Notion, KnowledgeSource::Meeting])
            ->where('indexed_at', '>=', $now->subDays(self::DAYS))
            ->select(['id', 'title', 'terms'])
            ->lazyById(100)
            ->each(function (KnowledgeDocument $document) use (&$counts, &$examples): void {
                foreach (array_unique(self::terms($document->title.' '.$document->terms)) as $term) {
                    $counts[$term] = ($counts[$term] ?? 0) + 1;

                    if (count($examples[$term] ?? []) < 3) {
                        $examples[$term][] = "Aparece en «{$document->title}»";
                    }
                }
            });

        $counts = array_filter($counts, fn (int $count): bool => $count >= self::MIN_DOCUMENTS);
        arsort($counts);

        $candidates = [];

        foreach ($counts as $term => $count) {
            $term = (string) $term;

            if ($this->known($user, $term)) {
                continue;
            }

            $candidates[] = ['term' => $term, 'documents' => $count, 'examples' => $examples[$term] ?? []];

            if (count($candidates) >= self::CANDIDATES) {
                break;
            }
        }

        $noticed = 0;

        foreach ($this->judge->judge($user, $candidates) as $asked) {
            $documents = collect($candidates)->firstWhere('term', $asked['term'])['documents'] ?? 0;

            $user->knowledgeGaps()->create([
                'kind' => GapKind::Term,
                'subject_key' => $asked['term'],
                'subject' => $asked['term'],
                'question' => $asked['question'],
                'context' => ['documents' => $documents],
                'status' => GapStatus::Open,
                'priority' => min(99, $documents * 5),
            ]);
            $noticed++;
        }

        return $noticed;
    }

    /**
     * Uppercase words of two to eight characters that are not Jira keys
     * (PROJ-123) and not on the common list.
     *
     * @return list<string>
     */
    public static function terms(string $text): array
    {
        preg_match_all('/(?<![\p{L}\p{N}-])[A-Z][A-Z0-9]{1,7}(?![\p{L}\p{N}]|-\d)/u', $text, $matches);

        return array_values(array_filter(
            $matches[0],
            fn (string $term): bool => ! in_array($term, self::COMMON, true) && preg_match('/^\d+$/', $term) !== 1,
        ));
    }

    /**
     * Already asked (in any state), or explained in the archive or an answer.
     */
    private function known(User $user, string $term): bool
    {
        if ($user->knowledgeGaps()->where('kind', GapKind::Term)->where('subject_key', $term)->exists()) {
            return true;
        }

        return $user->knowledgeDocuments()
            ->whereIn('source', [KnowledgeSource::Archive, KnowledgeSource::Memory])
            ->where(fn ($documents) => $documents->where('terms', 'like', "%{$term}%")->orWhere('summary', 'like', "%{$term}%"))
            ->exists();
    }
}
