<?php

$open = [
    'op' => 'open',
    'kind' => 'question',
    'what' => 'Confirmar el horario',
    'requesters' => ['ana'],
    'responders' => ['self'],
    'quote' => '¿Confirmamos el horario?',
];

return [
    'people' => ['ana' => 'Ana Ruiz'],
    'phases' => [[
        'messages' => [
            ['name' => 'ask', 'author' => 'ana', 'text' => '¿Confirmamos el horario?'],
        ],
        'segments' => [[
            'messages' => ['ask'],
            'new' => ['ask'],
            'restated' => 'Ana pregunta por el horario',
            'link' => ['mode' => 'new_topic', 'quote' => '¿Confirmamos el horario?'],
            'runs' => [
                [$open],
                [$open],
                [['op' => 'noise', 'quote' => '¿Confirmamos el horario?']],
            ],
            'critic' => 'holds',
        ]],
    ]],
    'expect' => [
        'intents' => 1,
        'possible_ask' => true,
        'kind' => 'question',
        'responder' => 'self',
        'playbooks' => false,
        'proposals' => 0,
        'calls' => 8,
    ],
];
