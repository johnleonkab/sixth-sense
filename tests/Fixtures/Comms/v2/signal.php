<?php

return [
    'people' => ['ana' => 'Ana Ruiz'],
    'topic' => ['subject' => 'workstream:prepago', 'alias' => 'prepago'],
    'phases' => [[
        'messages' => [[
            'name' => 'shipped',
            'author' => 'ana',
            'text' => 'El prepago ya salió a producción',
        ]],
        'segments' => [[
            'messages' => ['shipped'],
            'new' => ['shipped'],
            'restated' => 'Ana avisa que el prepago salió',
            'link' => ['mode' => 'topic_new', 'quote' => 'El prepago ya salió a producción'],
            'operations' => [[
                'op' => 'signal',
                'summary' => 'El prepago salió a producción',
                'quote' => 'El prepago ya salió a producción',
            ]],
        ]],
    ]],
    'expect' => [
        'intents' => 0,
        'signals' => 1,
        'signal_topic' => 'workstream:prepago',
    ],
];
