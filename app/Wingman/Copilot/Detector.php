<?php

namespace App\Wingman\Copilot;

use App\Models\WingmanMeeting;

/**
 * Reads what was just said in a call and says what is worth looking into.
 */
interface Detector
{
    /**
     * @param  list<array{at: string, speaker: string, is_me: bool, text: string}>  $earlier  What was said just before, for context.
     * @param  list<array{at: string, speaker: string, is_me: bool, text: string}>  $new  What to look at.
     * @param  list<string>  $known  What Wingman already brought in this call.
     * @return list<Finding>
     */
    public function detect(WingmanMeeting $meeting, array $earlier, array $new, array $known): array;
}
