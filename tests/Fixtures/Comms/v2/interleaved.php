<?php

return [
    'people' => ['ana' => 'Ana Ruiz', 'luis' => 'Luis Gómez'],
    'phases' => [[
        'messages' => [
            ['name' => 'prepago', 'author' => 'ana', 'text' => 'El prepago del viernes'],
            ['name' => 'banner', 'author' => 'luis', 'text' => 'El diseño del banner'],
            ['name' => 'friday', 'author' => 'ana', 'text' => 'Sí, antes del viernes'],
            ['name' => 'blue', 'author' => 'luis', 'text' => 'Usa el azul'],
        ],
        'segments' => [
            [
                'messages' => ['prepago', 'friday'],
                'new' => ['prepago', 'friday'],
                'restated' => 'Ana pregunta si el prepago puede quedar antes del viernes.',
                'link' => ['mode' => 'new_topic', 'quote' => 'El prepago del viernes'],
                'operations' => [[
                    'op' => 'open',
                    'kind' => 'request',
                    'what' => 'Dejar el prepago antes del viernes',
                    'requesters' => ['ana'],
                    'responders' => ['self'],
                    'quote' => 'El prepago del viernes',
                ]],
                'critic' => 'holds',
            ],
            [
                'messages' => ['banner', 'blue'],
                'new' => ['banner', 'blue'],
                'restated' => 'Luis pide el banner en azul.',
                'link' => ['mode' => 'new_topic', 'quote' => 'Usa el azul'],
                'operations' => [[
                    'op' => 'open',
                    'kind' => 'request',
                    'what' => 'Usar azul en el banner',
                    'requesters' => ['luis'],
                    'responders' => ['self'],
                    'quote' => 'Usa el azul',
                ]],
                'critic' => 'holds',
            ],
        ],
    ]],
    'expect' => [
        'intents' => 2,
        'segments' => 2,
        'windows' => 1,
    ],
];
