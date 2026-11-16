<?php

return [
    'people' => ['ana' => 'Ana Ruiz'],
    'topic' => ['subject' => 'workstream:prepago', 'alias' => 'prepago'],
    'priors' => [
        ['name' => 'flow', 'what' => 'Revisar el flujo de prepago'],
    ],
    'phases' => [[
        'messages' => [
            ['name' => 'invoice', 'author' => 'ana', 'text' => 'Hay que pedir la factura del proveedor'],
        ],
        'segments' => [[
            'messages' => ['invoice'],
            'new' => ['invoice'],
            'restated' => 'Ana pide la factura en el hilo del prepago',
            'link' => ['mode' => 'topic_new', 'quote' => 'Hay que pedir la factura del proveedor'],
            'operations' => [[
                'op' => 'open',
                'kind' => 'request',
                'what' => 'Pedir factura proveedor',
                'requesters' => ['ana'],
                'responders' => ['self'],
                'quote' => 'Hay que pedir la factura del proveedor',
            ]],
            'critic' => 'holds',
        ]],
    ]],
    'expect' => [
        'intents' => 2,
        'unchanged' => 'flow',
    ],
];
