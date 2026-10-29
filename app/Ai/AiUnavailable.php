<?php

namespace App\Ai;

use RuntimeException;

/**
 * No model of a task could answer it: out of quota, down, too slow, not
 * configured, or answering what was not asked. The next one is tried; when
 * none is left, the caller decides (rules instead, try again later…).
 */
class AiUnavailable extends RuntimeException {}
