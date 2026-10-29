<?php

namespace App\WorkContext;

use Carbon\CarbonImmutable;
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

    /**
     * The context as the voice assistant reads it at the start of each conversation.
     */
    public function forAssistant(CarbonImmutable $generatedAt): string
    {
        $workstreams = array_map(fn (array $stream): string => '- '.implode(' ', array_filter([
            "{$stream['name']}: {$stream['what']}.",
            $stream['why'] ? "Por qué: {$stream['why']}." : null,
            $stream['with'] !== [] ? 'Con: '.implode(', ', $stream['with']).'.' : null,
            $stream['where'] !== [] ? 'Dónde: '.implode(', ', $stream['where']).'.' : null,
            $stream['next'] ? "Siguiente paso: {$stream['next']}." : null,
        ])), $this->workstreams);

        $people = implode('; ', array_map(
            fn (array $person): string => $person['name'].($person['relation'] !== '' ? " ({$person['relation']})" : ''),
            $this->people,
        ));

        return implode("\n", array_filter([
            'Contexto de su trabajo estos días, preparado el '.$generatedAt->locale('es')->isoFormat('dddd D [de] MMMM').' leyendo (sin cambiar nada) su Slack, su correo, su Drive, su Notion y su Jira. '
                .'Úsalo para entender de qué y de quién habla, en qué idioma, y cómo escribe, sin que te lo explique. No lo recites ni digas que lo tienes si no viene a cuento. '
                .'Puede estar desactualizado: lo que veas con tus herramientas manda.',
            "Resumen: {$this->summary}",
            $workstreams !== [] ? "En qué está:\n".implode("\n", $workstreams) : null,
            $people !== '' ? "Personas: {$people}." : null,
            $this->contacts !== [] ? "Contactos frecuentes (idioma en el que hay que hablarles):\n".implode("\n", array_map(
                fn (array $contact): string => '- '.$contact['name']
                    .($contact['language'] !== '' ? " · {$contact['language']}" : '')
                    .($contact['where'] !== '' ? " · {$contact['where']}" : '')
                    .($contact['note'] !== '' ? " · {$contact['note']}" : ''),
                $this->contacts,
            )) : null,
            $this->writingSlack !== '' ? "Cómo escribe en Slack: {$this->writingSlack}" : null,
            $this->writingGmail !== '' ? "Cómo escribe en Gmail: {$this->writingGmail}" : null,
            $this->hotFiles !== [] ? "Archivos calientes:\n".implode("\n", array_map(fn (array $file): string => "- {$file['title']}".($file['why'] !== '' ? ": {$file['why']}" : ''), $this->hotFiles)) : null,
            $this->hotPages !== [] ? "Páginas calientes de Notion:\n".implode("\n", array_map(fn (array $page): string => "- {$page['title']}".($page['why'] !== '' ? ": {$page['why']}" : ''), $this->hotPages)) : null,
            $this->jira !== [] ? 'Jira reciente: '.implode('; ', $this->jira).'.' : null,
            $this->openLoops !== [] ? 'Pendiente: '.implode('; ', $this->openLoops).'.' : null,
        ]));
    }
}
