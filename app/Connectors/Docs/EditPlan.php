<?php

namespace App\Connectors\Docs;

/**
 * Edits resolved against a specific revision of a document: what the person
 * sees before confirming, and the operations that will run afterwards.
 */
final readonly class EditPlan
{
    /**
     * @param  list<array{type: string, before: string, removed: string, inserted: string, after: string}>  $changes
     * @param  list<array{type: 'delete'|'insert', start?: int, end?: int, index?: int, text?: string}>  $operations  Last position first.
     */
    public function __construct(
        public string $ref,
        public string $title,
        public ?string $revisionId,
        public array $changes,
        public array $operations,
        public ?string $url = null,
    ) {}

    /**
     * Resolve the edits against the document's current text.
     *
     * @param  list<DocumentEdit>  $edits
     *
     * @throws EditNotPossible
     */
    public static function for(Document $document, array $edits): self
    {
        $text = $document->plainText();
        $resolved = [];

        foreach ($edits as $edit) {
            $matches = $document->locate($edit->find);

            if ($matches === []) {
                throw new EditNotPossible("No encuentro el texto «{$edit->find}» en el documento. Cítalo exactamente como aparece.");
            }

            if (count($matches) > 1) {
                throw new EditNotPossible('El texto «'.$edit->find.'» aparece '.count($matches).' veces. Cita un fragmento más largo para que sea único.');
            }

            $resolved[] = self::narrow($edit, $text, $matches[0]);
        }

        usort($resolved, fn (array $a, array $b): int => $b['from'] <=> $a['from']);

        foreach (array_slice($resolved, 1) as $i => $item) {
            if ($item['to'] > $resolved[$i]['from']) {
                throw new EditNotPossible('Dos cambios se solapan. Combínalos en uno.');
            }
        }

        $operations = [];
        $changes = [];

        foreach ($resolved as ['edit' => $edit, 'from' => $from, 'to' => $to]) {
            $start = $document->indexAt($from);
            $end = $document->indexAt($to);

            $operations = [...$operations, ...match ($edit->type) {
                'replace' => [['type' => 'delete', 'start' => $start, 'end' => $end], ['type' => 'insert', 'index' => $start, 'text' => $edit->text]],
                'delete' => [['type' => 'delete', 'start' => $start, 'end' => $end]],
                'insert_before' => [['type' => 'insert', 'index' => $start, 'text' => $edit->text]],
                'insert_after' => [['type' => 'insert', 'index' => $end, 'text' => $edit->text]],
            }];

            $found = mb_substr($text, $from, $to - $from);

            $changes[] = [
                'type' => $edit->type,
                'before' => self::context(mb_substr($text, max(0, $from - 120), min($from, 120)), fromEnd: true, cut: $from > 120).($edit->type === 'insert_after' ? $found : ''),
                'removed' => in_array($edit->type, ['replace', 'delete'], true) ? $found : '',
                'inserted' => $edit->type === 'delete' ? '' : $edit->text,
                'after' => ($edit->type === 'insert_before' ? $found : '').self::context(mb_substr($text, $to, 120)),
            ];
        }

        return new self(
            ref: $document->ref,
            title: $document->title,
            revisionId: $document->revisionId,
            changes: array_reverse($changes),
            operations: array_values(array_filter($operations, fn (array $operation): bool => $operation['type'] === 'delete' || $operation['text'] !== '')),
            url: $document->url,
        );
    }

    /**
     * Shrink a replacement to what actually changes ("Recordatorio 24 horas" →
     * "Recordatorio 48 horas" only touches "24"), so less text is rewritten and
     * the draft shows just the difference.
     *
     * @param  array{from: int, to: int}  $match
     * @return array{from: int, to: int, edit: DocumentEdit}
     */
    private static function narrow(DocumentEdit $edit, string $text, array $match): array
    {
        if ($edit->type !== 'replace') {
            return [...$match, 'edit' => $edit];
        }

        $old = mb_str_split(mb_substr($text, $match['from'], $match['to'] - $match['from']));
        $new = mb_str_split($edit->text);
        $prefix = 0;

        while ($prefix < count($old) && $prefix < count($new) && $old[$prefix] === $new[$prefix]) {
            $prefix++;
        }

        $suffix = 0;

        while ($suffix < count($old) - $prefix && $suffix < count($new) - $prefix
            && $old[count($old) - 1 - $suffix] === $new[count($new) - 1 - $suffix]) {
            $suffix++;
        }

        return [
            'from' => $match['from'] + $prefix,
            'to' => $match['to'] - $suffix,
            'edit' => new DocumentEdit('replace', $edit->find, implode('', array_slice($new, $prefix, count($new) - $prefix - $suffix))),
        ];
    }

    /**
     * Context around a change, cut at word boundaries and kept to its paragraph.
     */
    private static function context(string $text, bool $fromEnd = false, bool $cut = false): string
    {
        $lines = explode("\n", $text);

        if (! $fromEnd) {
            return $lines[0];
        }

        // Only when the paragraph started before the excerpt is there a half word to drop.
        return count($lines) === 1 && $cut ? (string) preg_replace('/^\S*\s(?=\S)/u', '…', $lines[0], 1) : end($lines);
    }
}
