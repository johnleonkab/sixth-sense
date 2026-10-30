<?php

namespace App\Connectors\Mcp;

use RuntimeException;

/**
 * An MCP server could not be reached or answered with an error. The message
 * is meant for the person.
 */
class McpError extends RuntimeException {}
