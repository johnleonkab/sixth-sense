<?php

return [
    'people' => ['ana' => 'Ana Ruiz'],
    'phases' => [[
        'source' => 'gmail',
        'channel' => 'gmail-cc',
        'messages' => [[
            'name' => 'mail',
            'author' => 'ana',
            'text' => '¿Puedes revisar el contrato?',
            'flags' => ['cc_only' => true],
        ]],
        'segments' => [[
            'messages' => ['mail'],
            'new' => ['mail'],
            'restated' => 'Ana pide revisar el contrato en copia',
            'link' => ['mode' => 'new_topic', 'quote' => '¿Puedes revisar el contrato?'],
            'operations' => [[
                'op' => 'open',
                'kind' => 'request',
                'what' => 'Revisar el contrato',
                'requesters' => ['ana'],
                'responders' => ['self'],
                'quote' => '¿Puedes revisar el contrato?',
            ]],
            'critic' => 'holds',
        ]],
    ]],
    'expect' => ['intents' => 1],
];
