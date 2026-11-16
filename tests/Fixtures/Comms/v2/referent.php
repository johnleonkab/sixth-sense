<?php

return [
    'people' => ['ana' => 'Ana Ruiz', 'luis' => 'Luis Gómez'],
    'phases' => [[
        'lookback' => [
            ['name' => 'referent', 'author' => 'ana', 'text' => 'flujo de prepago', 'hours_before' => 12],
        ],
        'lookback_notes' => ['author' => 'ana', 'count' => 11],
        'messages' => [
            ['name' => 'ask', 'author' => 'luis', 'text' => '¿viste eso?'],
        ],
        'segments' => [[
            'messages' => ['referent', 'ask'],
            'new' => ['ask'],
            'restated' => 'Luis pregunta a Ana si vio el flujo de prepago.',
            'link' => ['mode' => 'new_topic', 'quote' => '¿viste eso?'],
            'operations' => [[
                'op' => 'open',
                'kind' => 'question',
                'what' => 'Confirmar si Ana vio el flujo de prepago',
                'requesters' => ['luis'],
                'responders' => ['ana'],
                'quote' => '¿viste eso?',
            ]],
            'critic' => 'holds',
        ]],
    ]],
    'expect' => [
        'intents' => 1,
        'links' => 1,
        'restated_contains' => 'flujo de prepago',
    ],
];
