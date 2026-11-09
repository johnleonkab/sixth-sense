<?php

namespace App\Comms\Playbooks\Steps;

/**
 * These playbooks do not arm a watcher. The step exists so a later
 * playbook can pause on one.
 */
class Watch
{
    public function arm(): null
    {
        return null;
    }
}
