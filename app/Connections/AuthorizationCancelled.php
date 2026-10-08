<?php

namespace App\Connections;

use RuntimeException;

/** The person said no at the provider. */
class AuthorizationCancelled extends RuntimeException {}
