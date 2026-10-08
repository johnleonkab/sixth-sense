<?php

namespace App\Connections;

use RuntimeException;

/**
 * The provider rejected the account's credentials for good (revoked access,
 * changed password, expired refresh token…). The user has to connect again.
 */
class AccessRevoked extends RuntimeException {}
