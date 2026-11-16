<?php

return [
    'people' => ['ana' => 'Ana Ruiz'],
    'phases' => [[
        'channel' => 'D1',
        'messages' => [[
            'name' => 'dm',
            'author' => 'ana',
            'text' => 'Necesito el informe de marzo',
            'repeat' => 8,
        ]],
        'segments' => [[
            'messages' => '*',
            'new' => '*',
            'restated' => 'Ana pide el informe de marzo',
            'link' => ['mode' => 'new_topic', 'quote' => 'Necesito el informe de marzo'],
            'operations' => [[
                'op' => 'open',
                'kind' => 'request',
                'what' => 'Enviar el informe de marzo',
                'requesters' => ['ana'],
                'responders' => ['self'],
                'quote' => 'Necesito el informe de marzo',
            ]],
            'critic' => 'holds',
        ]],
    ]],
    'expect' => [
        'intents' => 1,
        'windows' => 1,
    ],
];
