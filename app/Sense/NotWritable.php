<?php

namespace App\Sense;

use RuntimeException;

/**
 * The connected account cannot commit to the repository that would become
 * the work archive.
 */
class NotWritable extends RuntimeException {}
