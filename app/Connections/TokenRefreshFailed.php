<?php

namespace App\Connections;

use RuntimeException;

/**
 * A refresh attempt failed for a reason that may be temporary.
 */
class TokenRefreshFailed extends RuntimeException {}
