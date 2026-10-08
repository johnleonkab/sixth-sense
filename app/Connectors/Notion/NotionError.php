<?php

namespace App\Connectors\Notion;

use RuntimeException;

/**
 * Notion refused a request: object_not_found, restricted_resource, validation_error…
 */
class NotionError extends RuntimeException
{
    public function __construct(public readonly string $notionCode, string $message, int $status)
    {
        parent::__construct($message, $status);
    }
}
