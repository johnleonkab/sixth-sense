<?php

return [
    'people' => ['luis' => 'Luis Gómez'],
    'topic' => ['subject' => 'workstream:prepago', 'alias' => 'prepago'],
    'priors' => [
        ['name' => 'flow', 'what' => 'Revisar el flujo de prepago', 'responder' => 'self'],
    ],
    'phases' => [[
        'messages' => [[
            'name' => 'take',
            'author' => 'luis',
            'text' => 'Lo cojo yo, no te preocupes',
        ]],
        'segments' => [[
            'messages' => ['take'],
            'new' => ['take'],
            'restated' => 'Luis se queda el prepago',
            'link' => ['mode' => 'existing', 'intent' => 'flow', 'quote' => 'Lo cojo yo, no te preocupes'],
            'operations' => [[
                'op' => 'settle',
                'intent' => 'flow',
                'settle_reason' => 'taken_by_other',
                'quote' => 'Lo cojo yo, no te preocupes',
            ]],
            'critic' => 'holds',
        ]],
    ]],
    'expect' => [
        'same_intent' => 'flow',
        'state' => 'settled',
        'settle_reason' => 'taken_by_other',
    ],
];
