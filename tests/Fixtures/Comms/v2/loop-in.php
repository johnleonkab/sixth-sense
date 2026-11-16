<?php

return [
    'people' => ['marta' => 'Marta'],
    'topic' => ['subject' => 'workstream:prepago', 'alias' => 'prepago'],
    'priors' => [
        ['name' => 'flow', 'what' => 'Revisar el flujo de prepago', 'responder' => 'self'],
    ],
    'phases' => [[
        'messages' => [[
            'name' => 'loop',
            'author' => 'self',
            'text' => 'Loopeo a Marta',
            'direction' => 'out',
        ]],
        'segments' => [[
            'messages' => ['loop'],
            'new' => ['loop'],
            'restated' => 'John le pasa el prepago a Marta',
            'link' => ['mode' => 'existing', 'intent' => 'flow', 'quote' => 'Loopeo a Marta'],
            'operations' => [[
                'op' => 'update',
                'intent' => 'flow',
                'responders' => ['marta'],
                'quote' => 'Loopeo a Marta',
            ]],
        ]],
    ]],
    'expect' => [
        'same_intent' => 'flow',
        'responder' => 'marta',
    ],
];
