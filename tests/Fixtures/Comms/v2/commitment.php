<?php

return [
    'phases' => [[
        'messages' => [[
            'name' => 'promise',
            'author' => 'self',
            'text' => 'te lo mando el jueves',
            'direction' => 'out',
        ]],
        'segments' => [[
            'messages' => ['promise'],
            'new' => ['promise'],
            'restated' => 'John promete enviar el documento el jueves',
            'link' => ['mode' => 'new_topic', 'quote' => 'te lo mando el jueves'],
            'operations' => [[
                'op' => 'open',
                'kind' => 'commitment',
                'what' => 'Enviar el documento el jueves',
                'responders' => ['self'],
                'quote' => 'te lo mando el jueves',
                'deadline' => ['date' => '2026-03-19', 'text' => 'el jueves'],
            ]],
            'critic' => 'holds',
        ]],
    ]],
    'expect' => [
        'intents' => 1,
        'kind' => 'commitment',
        'responder' => 'self',
        'deadline' => '2026-03-19',
        'deadline_text' => 'el jueves',
        'state' => 'open',
    ],
];
