<?php

return [
    'people' => ['ana' => 'Ana Ruiz'],
    'topic' => [
        'subject' => 'workstream:prepago',
        'alias' => 'prepago',
        'document' => 'El prepago de primavera.',
    ],
    'phases' => [
        [
            'source' => 'slack',
            'channel' => 'C1',
            'messages' => [
                ['name' => 'slack', 'author' => 'ana', 'text' => 'Hay que revisar el prepago'],
            ],
            'segments' => [[
                'messages' => ['slack'],
                'new' => ['slack'],
                'restated' => 'Ana pide revisar el prepago',
                'link' => ['mode' => 'topic_new', 'quote' => 'Hay que revisar el prepago'],
                'operations' => [[
                    'op' => 'open',
                    'kind' => 'request',
                    'what' => 'Revisar el prepago',
                    'requesters' => ['ana'],
                    'responders' => ['self'],
                    'quote' => 'Hay que revisar el prepago',
                ]],
                'critic' => 'holds',
            ]],
        ],
        [
            'source' => 'gmail',
            'channel' => 'gmail-9',
            'messages' => [
                ['name' => 'email', 'author' => 'ana', 'text' => 'Sigo con el prepago por email'],
            ],
            'segments' => [[
                'messages' => ['email'],
                'new' => ['email'],
                'restated' => 'Ana sigue con el prepago por email',
                'link' => ['mode' => 'existing', 'intent' => 'latest', 'quote' => 'Sigo con el prepago por email'],
                'operations' => [[
                    'op' => 'attach',
                    'intent' => 'latest',
                    'quote' => 'Sigo con el prepago por email',
                ]],
            ]],
        ],
    ],
    'expect' => [
        'intents' => 1,
        'links' => 2,
        'windows' => 2,
    ],
];
