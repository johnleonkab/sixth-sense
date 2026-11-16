<?php

return [
    'people' => ['ana' => 'Ana Ruiz'],
    'phases' => [[
        'messages' => [
            ['name' => 'coffee', 'author' => 'ana', 'text' => 'we should grab a coffee sometime'],
        ],
        'segments' => [[
            'messages' => ['coffee'],
            'new' => ['coffee'],
            'restated' => 'Ana propone un café',
            'link' => ['mode' => 'new_topic', 'quote' => 'we should grab a coffee sometime'],
            'operations' => [[
                'op' => 'open',
                'kind' => 'question',
                'what' => 'Quedar para un café',
                'requesters' => ['ana'],
                'responders' => ['self'],
                'quote' => 'we should grab a coffee sometime',
            ]],
            'critic' => ['question' => 'real_ask', 'quote' => 'we should grab a coffee sometime'],
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
