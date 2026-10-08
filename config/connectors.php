<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Demo mode
    |--------------------------------------------------------------------------
    |
    | Use each connector's Fake, with the sample data from docs/prototype.html,
    | instead of the connected accounts. Lets you try sixth-sense without
    | connecting anything.
    |
    */

    'demo' => (bool) env('CONNECTORS_DEMO', false),

];
