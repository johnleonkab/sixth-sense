<?php

namespace App\Comms\Intents;

enum IntentLinkKind: string
{
    case DependsOn = 'depends_on';
    case MergedInto = 'merged_into';
    case SplitFrom = 'split_from';
    case Spawned = 'spawned';
}
