<?php

namespace App\Connectors\Wiki;

use App\Connections\Capability;
use App\Connectors\Docs\DocumentComment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Sample Notion pages in the spirit of docs/prototype.html, for tests and
 * demo mode. Pages, edits and comments are kept in memory.
 */
class FakeWiki implements Wiki
{
    public const LAUNCH = 'demo:1a2b3c4d-0000-4000-8000-000000000001';

    public const ONE_ON_ONE = 'demo:1a2b3c4d-0000-4000-8000-000000000002';

    /** @var list<array{parent: string, title: string, markdown: string}> */
    public array $created = [];

    /** @var list<array{ref: string, content: string, thread: ?string}> */
    public array $comments = [];

    /** @var array<string, string> Markdown changed by edits, by ref. */
    private array $edited = [];

    public function search(User $user, ?string $query, int $limit = 10): array
    {
        return array_slice(array_values(array_filter(
            array_map(fn (string $ref): WikiPageSummary => $this->summary($ref), array_keys($this->samples())),
            fn (WikiPageSummary $page): bool => blank($query) || Str::contains(Str::lower(Str::ascii($page->title)), Str::lower(Str::ascii((string) $query))),
        )), 0, $limit);
    }

    public function outline(User $user, array $widths): array
    {
        return array_map(
            fn (WikiPageSummary $page): array => ['page' => $page, 'depth' => 0],
            array_slice($this->search($user, null, $widths[0] ?? 10), 0, $widths[0] ?? 10),
        );
    }

    public function page(User $user, string $reference): ?WikiPage
    {
        $ref = isset($this->samples()[$reference]) ? $reference : collect(array_keys($this->samples()))->first(fn (string $ref): bool => Str::contains($reference, Str::after($ref, 'demo:')) || Str::contains($reference, str_replace('-', '', Str::after($ref, 'demo:'))));

        if ($ref === null) {
            return null;
        }

        $sample = $this->samples()[$ref];

        return new WikiPage($ref, $sample['title'], $sample['icon'], $sample['url'], $sample['public_url'], $this->edited[$ref] ?? $sample['markdown'], 'CoverManager', CarbonImmutable::today()->setTime(9, 0));
    }

    public function comments(User $user, string $ref): array
    {
        if ($ref !== self::LAUNCH) {
            return [];
        }

        $today = CarbonImmutable::today();

        return [
            new DocumentComment('d1', 'Marta Ruiz', false, '¿Llegamos al 20 con Pagos? Si no, lo movemos.', 'Lanzamiento: 20 de octubre', $today->setTime(9, 5), replies: [
                ['id' => 'c2', 'author' => 'Pablo Gil', 'mine' => false, 'content' => 'Por mi parte sí, falta el ok de Riesgos.', 'created_at' => $today->setTime(9, 20)],
            ]),
            new DocumentComment('d2', 'John León', true, 'Añadir el plan de comunicación.', null, $today->setTime(9, 40)),
        ];
    }

    public function allows(User $user, string $ref, Capability $capability): bool
    {
        return str_starts_with($ref, 'demo:');
    }

    public function create(User $user, string $parentRef, string $title, string $markdown): WikiPageSummary
    {
        $this->created[] = ['parent' => $parentRef, 'title' => $title, 'markdown' => $markdown];

        return new WikiPageSummary('demo:new-'.count($this->created), $title, null, 'https://www.notion.so/demo-new-'.count($this->created), 'CoverManager', CarbonImmutable::now());
    }

    public function edit(User $user, string $ref, array $edits): void
    {
        $markdown = $this->page($user, $ref)->markdown;

        foreach ($edits as $edit) {
            $markdown = $edit->type === 'append'
                ? rtrim($markdown)."\n".$edit->text
                : Str::replaceFirst($edit->find, $edit->replacement(), $markdown);
        }

        $this->edited[$ref] = $markdown;
    }

    public function comment(User $user, string $ref, string $content, ?string $threadId = null): string
    {
        $this->comments[] = ['ref' => $ref, 'content' => $content, 'thread' => $threadId];

        return 'comment-'.count($this->comments);
    }

    private function summary(string $ref): WikiPageSummary
    {
        $sample = $this->samples()[$ref];

        return new WikiPageSummary($ref, $sample['title'], $sample['icon'], $sample['url'], 'CoverManager', CarbonImmutable::today()->setTime(9, 0), $sample['parent']);
    }

    /**
     * @return array<string, array{title: string, icon: ?string, url: string, public_url: ?string, parent: ?string, markdown: string}>
     */
    private function samples(): array
    {
        return [
            self::LAUNCH => [
                'title' => 'Plan de lanzamiento: prepago',
                'icon' => '🚀',
                'url' => 'https://www.notion.so/covermanager/Plan-de-lanzamiento-prepago-1a2b3c4d000040008000000000000001',
                'public_url' => null,
                'parent' => 'Producto',
                'markdown' => implode("\n", [
                    '# Plan de lanzamiento',
                    'Lanzamiento: 20 de octubre',
                    '## Objetivos',
                    '- Reducir los no-shows un **30 %**',
                    '- Cobrar por adelantado en el paso 3',
                    '## Tareas',
                    '- [x] Diseño del flujo en Figma',
                    '- [ ] Revisión de Riesgos',
                    '- [ ] Plan de comunicación',
                    '> El reembolso parcial sigue en el paso 5.',
                ]),
            ],
            self::ONE_ON_ONE => [
                'title' => 'Notas 1:1 con Marta',
                'icon' => null,
                'url' => 'https://www.notion.so/covermanager/Notas-1-1-con-Marta-1a2b3c4d000040008000000000000002',
                'public_url' => 'https://covermanager.notion.site/Notas-1-1-con-Marta-1a2b3c4d000040008000000000000002',
                'parent' => null,
                'markdown' => "# Notas 1:1\n- Revisar el prepago antes del jueves\n- Preparar la demo para Grupo Vela",
            ],
        ];
    }
}
