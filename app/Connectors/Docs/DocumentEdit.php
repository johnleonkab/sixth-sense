<?php

namespace App\Connectors\Docs;

/**
 * One change the assistant wants to make, anchored to text in the document.
 */
final readonly class DocumentEdit
{
    public const TYPES = ['replace', 'insert_after', 'insert_before', 'delete'];

    public function __construct(
        public string $type,
        public string $find,
        public string $text = '',
    ) {}
}
