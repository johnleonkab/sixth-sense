<?php

namespace App\WorkContext;

use Illuminate\Support\Str;

/**
 * What the person is working on lately: the processes they are in, why, with
 * whom and where, the people around them and what is still open.
 */
final readonly class WorkDigest
{
    public const MAX_WORKSTREAMS = 6;

    public const MAX_PEOPLE = 12;

    public const MAX_OPEN_LOOPS = 8;

    public const MAX_CONTACTS = 12;

    public const MAX_HOT = 8;

    public const MAX_JIRA = 8;

    /**
     * @param  list<array{name: string, what: string, why: ?string, with: list<string>, where: list<string>, next: ?string}>  $workstreams
     * @param  list<array{name: string, relation: string}>  $people
     * @param  list<string>  $openLoops
     * @param  list<array{name: string, language: string, where: string, note: string}>  $contacts
     * @param  list<array{title: string, why: string}>  $hotFiles
     * @param  list<array{title: string, why: string}>  $hotPages
     * @param  list<string>  $jira
     */
    public function __construct(
        public string $summary,
        public array $workstreams = [],
        public array $people = [],
        public array $openLoops = [],
        public string $writingSlack = '',
        public string $writingGmail = '',
        public array $contacts = [],
        public array $hotFiles = [],
        public array $hotPages = [],
        public array $jira = [],
    ) {}

    /**
     * From what the summarizer or the database hold, dropping what is empty
     * and cutting what is too long for a context the assistant reads every time.
     *
     * @param  array<string, mixed>  $digest
     */
    public static function fromArray(array $digest): self
    {
        $text = fn (mixed $value, int $limit = 200): ?string => is_string($value) && trim($value) !== '' ? Str::limit(trim($value), $limit) : null;
        $texts = fn (mixed $values, int $max = 6): array => array_slice(array_values(array_filter(array_map($text, is_array($values) ? $values : []))), 0, $max);

        $workstreams = array_values(array_filter(array_map(fn (mixed $stream): ?array => is_array($stream) && $text($stream['name'] ?? null) && $text($stream['what'] ?? null) ? [
            'name' => $text($stream['name'], 80),
            'what' => $text($stream['what']),
            'why' => $text($stream['why'] ?? null),
            'with' => $texts($stream['with'] ?? []),
            'where' => $texts($stream['where'] ?? []),
            'next' => $text($stream['next'] ?? null),
        ] : null, is_array($digest['workstreams'] ?? null) ? $digest['workstreams'] : [])));

        $people = array_values(array_filter(array_map(fn (mixed $person): ?array => is_array($person) && $text($person['name'] ?? null) ? [
            'name' => $text($person['name'], 80),
            'relation' => $text($person['relation'] ?? null, 120) ?? '',
        ] : null, is_array($digest['people'] ?? null) ? $digest['people'] : [])));

        $named = fn (mixed $items, int $max): array => array_slice(array_values(array_filter(array_map(
            fn (mixed $item): ?array => is_array($item) && $text($item['title'] ?? null, 120) ? [
                'title' => $text($item['title'], 120),
                'why' => $text($item['why'] ?? null, 200) ?? '',
            ] : null,
            is_array($items) ? $items : [],
        ))), 0, $max);

        $writing = is_array($digest['writing'] ?? null) ? $digest['writing'] : [];
        $contacts = array_values(array_filter(array_map(fn (mixed $contact): ?array => is_array($contact) && $text($contact['name'] ?? null, 80) ? [
            'name' => $text($contact['name'], 80),
            'language' => $text($contact['language'] ?? null, 40) ?? '',
            'where' => $text($contact['where'] ?? null, 80) ?? '',
            'note' => $text($contact['note'] ?? null, 160) ?? '',
        ] : null, is_array($digest['contacts'] ?? null) ? $digest['contacts'] : [])));

        return new self(
            summary: $text($digest['summary'] ?? null, 600) ?? '',
            workstreams: array_slice($workstreams, 0, self::MAX_WORKSTREAMS),
            people: array_slice($people, 0, self::MAX_PEOPLE),
            openLoops: $texts($digest['open_loops'] ?? [], self::MAX_OPEN_LOOPS),
            writingSlack: $text($writing['slack'] ?? null, 500) ?? '',
            writingGmail: $text($writing['gmail'] ?? null, 500) ?? '',
            contacts: array_slice($contacts, 0, self::MAX_CONTACTS),
            hotFiles: $named($digest['hot_files'] ?? [], self::MAX_HOT),
            hotPages: $named($digest['hot_pages'] ?? [], self::MAX_HOT),
            jira: $texts($digest['jira'] ?? [], self::MAX_JIRA),
        );
    }

    /**
     * @return array{summary: string, workstreams: list<array<string, mixed>>, people: list<array{name: string, relation: string}>, open_loops: list<string>}
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'workstreams' => $this->workstreams,
            'people' => $this->people,
            'open_loops' => $this->openLoops,
            'writing' => ['slack' => $this->writingSlack, 'gmail' => $this->writingGmail],
            'contacts' => $this->contacts,
            'hot_files' => $this->hotFiles,
            'hot_pages' => $this->hotPages,
            'jira' => $this->jira,
        ];
    }
}
