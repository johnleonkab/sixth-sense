<?php

namespace App\Knowledge\Gaps;

/**
 * Where a question stands: noticed, asked, answered, or not worth asking.
 */
enum GapStatus: string
{
    case Open = 'open';
    case Asked = 'asked';
    case Answered = 'answered';
    case Dismissed = 'dismissed';
}
