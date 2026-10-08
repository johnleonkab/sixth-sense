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

    /**
     * @param  list<array{name: string, what: string, why: ?string, with: list<string>, where: list<string>, next: ?string}>  $workstreams
     * @param  list<array{name: string, relation: string}>  $people
     * @param  list<string>  $openLoops
     */
    public function __construct(
        public string $summary,
        public array $workstreams = [],
        public array $people = [],
        public array $openLoops = [],
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

        return new self(
            summary: $text($digest['summary'] ?? null, 600) ?? '',
            workstreams: array_slice($workstreams, 0, self::MAX_WORKSTREAMS),
            people: array_slice($people, 0, self::MAX_PEOPLE),
            openLoops: $texts($digest['open_loops'] ?? [], self::MAX_OPEN_LOOPS),
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
            'Contexto de su trabajo estos días, preparado el '.$generatedAt->locale('es')->isoFormat('dddd D [de] MMMM').' leyendo (sin cambiar nada) su Slack, su correo, sus documentos y su Notion. '
                .'Úsalo para entender de qué y de quién habla sin que te lo explique y para proponer con criterio. No lo recites ni digas que lo tienes si no viene a cuento. '
                .'Puede estar desactualizado: lo que veas con tus herramientas manda.',
            "Resumen: {$this->summary}",
            $workstreams !== [] ? "En qué está:\n".implode("\n", $workstreams) : null,
            $people !== '' ? "Personas: {$people}." : null,
            $this->openLoops !== [] ? 'Pendiente: '.implode('; ', $this->openLoops).'.' : null,
        ]));
    }
}
