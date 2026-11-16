<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * InnoDB full-text indexes only see committed rows, so a search inside the
 * test transaction misses documents that were just written. On MySQL, the
 * tests that search those words leave the wrapping transaction and then
 * remove every table they filled.
 */
trait CommitsFullText
{
    /** Fragments of the Pest method names that search knowledge by its words. */
    private const FULL_TEXT_TESTS = [
        'the_search_finds_by_the_indexed_words',
        'asking_an_agent_sends_the_knowledge',
        'an_answer_by_voice_closes_the_question',
        'meeting_notes_from_Drive',
        'each_proposal_keeps_its_sources',
    ];

    protected function setUpCommitsFullText(): void
    {
        if (! $this->fullTextSearchMustSeeCommittedRows()) {
            return;
        }

        while (DB::transactionLevel() > 0) {
            DB::commit();
        }
    }

    protected function tearDownCommitsFullText(): void
    {
        if (! $this->fullTextSearchMustSeeCommittedRows()) {
            return;
        }

        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();

        $schema->withoutForeignKeyConstraints(function () use ($connection, $schema): void {
            foreach ($schema->getTables() as $table) {
                $name = $table['name'];

                if ($name === 'migrations' || ! $connection->table($name)->exists()) {
                    continue;
                }

                $connection->table($name)->truncate();
            }
        });
    }

    private function fullTextSearchMustSeeCommittedRows(): bool
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return false;
        }

        foreach (self::FULL_TEXT_TESTS as $test) {
            if (str_contains($this->name(), $test)) {
                return true;
            }
        }

        return false;
    }
}
