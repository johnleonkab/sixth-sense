<?php

namespace App\Comms\Linking;

/**
 * What one linker run answered (agent-definitions 3.2).
 */
enum LinkChoice: string
{
    /** A candidate topic, with an existing intent inside it or none. */
    case Topic = 'topic';

    /** A subject the team talks about that no candidate covers. */
    case NewTopic = 'new_topic';

    /** Two candidates fit and the text does not decide; `a` is the more likely. */
    case Ambiguous = 'ambiguous';

    /** Belongs to no topic worth tracking. */
    case None = 'none';
}
