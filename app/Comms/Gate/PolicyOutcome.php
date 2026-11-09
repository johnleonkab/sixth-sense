<?php

namespace App\Comms\Gate;

enum PolicyOutcome: string
{
    case Allow = 'allow';
    case Ask = 'ask';
    case Deny = 'deny';
}
