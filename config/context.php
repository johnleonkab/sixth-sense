<?php

return [

    /*
    | Facts the nightly read guessed expire when nothing confirms them.
    | A rule nobody reads expires later. What the user wrote does not expire.
    */
    'inferred_days' => 14,

    'rule_days' => 60,

    'questions_after' => 3,

    'questions_over_days' => 7,

];
