<?php

namespace App\Connectors\Docs;

use App\Connections\Capability;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Sample documents in the spirit of docs/prototype.html, for tests and demo
 * mode. Edits and comments are applied in memory.
 */
class FakeDocuments implements Documents
{
    public const PREPAGO_ID = '1PrepagoFlujoV4demoDocument0001';

    public const CONTRATO_ID = '1ContratoGrupoVelaDemoDocument02';

    /** @var list<EditPlan> */
    public array $appliedEdits = [];

    /** @var list<array{ref: string, content: string, quote: ?string}> */
    public array $comments = [];

    /** @var array<string, list<array{text: string, start: int}>> */
    private array $edited = [];

    public function search(User $user, DocumentSearch $search): DocumentSearchResult
    {
        $contains = fn (?string $haystack, ?string $needle): bool => $needle === null
            || Str::contains(Str::lower(Str::ascii((string) $haystack)), Str::lower(Str::ascii($needle)));

        $documents = array_values(array_filter($this->summaries(), fn (DocumentSummary $summary): bool => $contains($summary->title, $search->name)
            && $contains($this->find($user, $summary->ref)?->plainText(), $search->text)
            && $contains(($summary->owner['name'] ?? '').' '.($summary->owner['email'] ?? ''), $search->owner)
            && ($search->modifiedAfter === null || $summary->modifiedAt->greaterThanOrEqualTo($search->modifiedAfter))));

        return new DocumentSearchResult(array_slice($documents, 0, $search->limit), ['demo@sixth-sense.test']);
    }

    public function find(User $user, string $reference): ?Document
    {
        $id = str_starts_with($reference, 'demo.') ? substr($reference, 5) : DocumentLinks::idFrom($reference);
        $sample = $this->samples()[$id] ?? null;

        if ($sample === null) {
            return null;
        }

        [$title, $paragraphs] = $sample;
        $runs = $this->edited[$id] ?? $this->runs($paragraphs);

        return new Document(
            ref: "demo.{$id}",
            account: 'demo@sixth-sense.test',
            title: $title,
            // Constant: in demo mode the worker and the web server each keep their own copy.
            revisionId: 'demo-revision',
            blocks: $this->blocks($runs, $paragraphs),
            runs: $runs,
            url: "https://docs.google.com/document/d/{$id}/edit",
        );
    }

    public function allows(User $user, string $ref, Capability $capability): bool
    {
        return true;
    }

    public function applyEdits(User $user, EditPlan $plan): void
    {
        $document = $this->find($user, $plan->ref) ?? throw new EditNotPossible('No existe el documento.');

        if ($plan->revisionId !== $document->revisionId) {
            throw new EditNotPossible('El documento ha cambiado desde que propuse el cambio. Vuelve a pedírmelo.');
        }

        $text = $document->plainText();

        foreach ($plan->operations as $operation) {
            $text = $operation['type'] === 'delete'
                ? mb_substr($text, 0, $operation['start'] - 1).mb_substr($text, $operation['end'] - 1)
                : mb_substr($text, 0, $operation['index'] - 1).$operation['text'].mb_substr($text, $operation['index'] - 1);
        }

        $this->edited[substr($plan->ref, 5)] = [['text' => $text, 'start' => 1]];
        $this->appliedEdits[] = $plan;
    }

    public function addComment(User $user, string $ref, string $content, ?string $quote = null): string
    {
        $this->comments[] = ['ref' => $ref, 'content' => $content, 'quote' => $quote];

        return 'comment-'.count($this->comments);
    }

    /** @var list<array{ref: string, comment_id: string, content: string, resolve: bool}> */
    public array $replies = [];

    public function comments(User $user, string $ref): array
    {
        if ($ref !== 'demo.'.self::PREPAGO_ID) {
            return [];
        }

        $today = CarbonImmutable::today();

        return [
            new DocumentComment('c2', 'John León', true, 'Revisar el texto del recordatorio.', '24 horas', $today->setTime(8, 40)),
            new DocumentComment('c1', 'Pablo Gil', false, '¿El reembolso parcial sigue en el paso 5? Riesgos lo necesita ahí.', 'reembolso parcial', $today->setTime(9, 2), replies: [
                ['id' => 'r1', 'author' => 'Marta Ruiz', 'mine' => false, 'content' => 'Sí, no lo hemos movido.', 'created_at' => $today->setTime(9, 20)],
                ['id' => 'r2', 'author' => 'Pablo Gil', 'mine' => false, 'content' => '@John ¿lo confirmas tú?', 'created_at' => $today->setTime(9, 31)],
            ]),
        ];
    }

    public function replyToComment(User $user, string $ref, string $commentId, string $content, bool $resolve = false): string
    {
        $this->replies[] = ['ref' => $ref, 'comment_id' => $commentId, 'content' => $content, 'resolve' => $resolve];

        return 'reply-'.count($this->replies);
    }

    /**
     * @param  list<array{string, string}>  $paragraphs
     * @return list<array{text: string, start: int}>
     */
    private function runs(array $paragraphs): array
    {
        $runs = [];
        $index = 1;

        foreach ($paragraphs as [, $text]) {
            $runs[] = ['text' => $text."\n", 'start' => $index];
            $index += mb_strlen($text) + 1;
        }

        return $runs;
    }

    /**
     * @param  list<array{text: string, start: int}>  $runs
     * @param  list<array{string, string}>  $paragraphs
     * @return list<array<string, mixed>>
     */
    private function blocks(array $runs, array $paragraphs): array
    {
        $lines = explode("\n", rtrim(implode('', array_column($runs, 'text')), "\n"));
        $styles = array_column($paragraphs, 0);

        return array_map(fn (string $line, int $i): array => match ($styles[$i] ?? 'p') {
            'h1' => ['type' => 'heading', 'level' => 1, 'runs' => [['text' => $line]]],
            'h2' => ['type' => 'heading', 'level' => 2, 'runs' => [['text' => $line]]],
            'li' => ['type' => 'list_item', 'level' => 0, 'ordered' => false, 'runs' => [['text' => $line]]],
            default => ['type' => 'paragraph', 'runs' => [['text' => $line]]],
        }, $lines, array_keys($lines));
    }

    /**
     * @return list<DocumentSummary>
     */
    private function summaries(): array
    {
        $now = CarbonImmutable::now();

        return [
            new DocumentSummary('demo.'.self::PREPAGO_ID, 'demo@sixth-sense.test', 'Flujo de prepago v4 — PRD', ['name' => 'Marta Ruiz', 'email' => 'marta@covermanager.test'],
                $now->subDays(12), $now->subHours(3), ['name' => 'Marta Ruiz', 'email' => 'marta@covermanager.test'], 'https://docs.google.com/document/d/'.self::PREPAGO_ID.'/edit'),
            new DocumentSummary('demo.'.self::CONTRATO_ID, 'demo@sixth-sense.test', 'Contrato Grupo Vela — borrador', ['name' => 'Andrés Vela', 'email' => 'andres@grupovela.test'],
                $now->subDays(30), $now->subDay(), ['name' => 'Pablo Gil', 'email' => 'pablo@covermanager.test'], 'https://docs.google.com/document/d/'.self::CONTRATO_ID.'/edit'),
        ];
    }

    /**
     * @return array<string, array{string, list<array{string, string}>}>
     */
    private function samples(): array
    {
        return [
            self::PREPAGO_ID => ['Flujo de prepago v4 — PRD', [
                ['h1', 'Flujo de prepago v4'],
                ['p', 'Objetivo: que el cliente sepa cuánto va a pagar antes de comprometerse.'],
                ['h2', 'Propuesta'],
                ['li', 'Mostrar el importe total antes de elegir mesa (paso 3).'],
                ['li', 'Reembolso parcial automático según la política del local (paso 5).'],
                ['li', 'Recordatorio de pago 24 horas antes del evento.'],
                ['h2', 'Riesgos'],
                ['p', 'Si el reembolso parcial sale del paso 5, hay que repetir la revisión de riesgos con Pagos.'],
            ]],
            self::CONTRATO_ID => ['Contrato Grupo Vela — borrador', [
                ['h1', 'Contrato de servicio'],
                ['p', 'Entre CoverManager y Grupo Vela, para la gestión de reservas de sus cinco locales.'],
                ['h2', 'Cláusula de reembolso'],
                ['p', 'Los reembolsos parciales se aplicarán según la política de cada local.'],
            ]],
        ];
    }
}
