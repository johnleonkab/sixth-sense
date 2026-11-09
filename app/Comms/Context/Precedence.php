<?php

namespace App\Comms\Context;

use App\Models\Comms\CommsDocument;

/**
 * Code is what was built. Jira and Notion are what was decided.
 * A disagreement is returned. Nothing here picks a winner.
 */
class Precedence
{
    /**
     * @param  list<CommsDocument>  $documents
     * @return list<array{refs: list<string>, summary: string}>
     */
    public function findings(array $documents): array
    {
        $groups = [];

        foreach ($documents as $document) {
            if ($document->topic === null || $document->topic === '') {
                continue;
            }

            $groups[$document->topic][] = $document;
        }

        $findings = [];

        foreach ($groups as $docs) {
            $code = $this->latest($docs, ['code']);
            $decided = $this->latest($docs, ['jira', 'notion']);

            if ($code === null || $decided === null || trim($code->body) === trim($decided->body)) {
                continue;
            }

            $findings[] = [
                'refs' => [$code->ref, $decided->ref],
                'summary' => 'El código y la spec no coinciden: '.$code->ref.' '.$decided->ref.'.',
            ];
        }

        return $findings;
    }

    /**
     * @param  list<CommsDocument>  $documents
     * @param  list<string>  $kinds
     */
    private function latest(array $documents, array $kinds): ?CommsDocument
    {
        $found = array_values(array_filter(
            $documents,
            fn (CommsDocument $document): bool => in_array($document->kind, $kinds, true),
        ));

        if ($found === []) {
            return null;
        }

        usort($found, fn (CommsDocument $left, CommsDocument $right): int => $right->updated_at <=> $left->updated_at);

        return $found[0];
    }
}
