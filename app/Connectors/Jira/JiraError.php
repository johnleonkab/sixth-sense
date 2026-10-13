<?php

namespace App\Connectors\Jira;

use RuntimeException;

/**
 * Jira refused a request, with what it said.
 */
class JiraError extends RuntimeException {}
