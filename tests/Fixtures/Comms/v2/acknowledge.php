<?php

return [
    'topic' => ['subject' => 'workstream:prepago', 'alias' => 'prepago'],
    'priors' => [
        ['name' => 'flow', 'what' => 'Revisar el flujo de prepago', 'responder' => 'self'],
    ],
    'phases' => [[
        'messages' => [[
            'name' => 'thumb',
            'author' => 'self',
            'text' => '👍',
            'direction' => 'out',
        ]],
        'segments' => [[
            'messages' => ['thumb'],
            'new' => ['thumb'],
            'restated' => 'John confirma el prepago',
            'link' => ['mode' => 'existing', 'intent' => 'flow', 'quote' => '👍'],
            'operations' => [[
                'op' => 'acknowledge',
                'intent' => 'flow',
                'quote' => '👍',
            ]],
        ]],
    ]],
    'expect' => [
        'same_intent' => 'flow',
        'acknowledged' => true,
        'state' => 'open',
    ],
];
