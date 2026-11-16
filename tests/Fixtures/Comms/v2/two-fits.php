<?php

return [
    'people' => ['ana' => 'Ana Ruiz'],
    'topic' => ['subject' => 'workstream:prepago', 'alias' => 'prepago'],
    'priors' => [
        ['name' => 'flow', 'what' => 'Revisar el flujo de prepago'],
        ['name' => 'invoice', 'what' => 'Pedir la factura del proveedor'],
    ],
    'phases' => [[
        'messages' => [
            ['name' => 'follow', 'author' => 'ana', 'text' => 'Sigo con lo del prepago'],
        ],
        'segments' => [[
            'messages' => ['follow'],
            'new' => ['follow'],
            'restated' => 'Ana retoma el prepago',
            'link' => [
                'mode' => 'ambiguous',
                'a' => 'flow',
                'b' => 'invoice',
                'quote' => 'Sigo con lo del prepago',
            ],
            'operations' => [[
                'op' => 'attach',
                'intent' => 'flow',
                'quote' => 'Sigo con lo del prepago',
            ]],
        ]],
    ]],
    'expect' => [
        'intents' => 2,
        'alternative_of' => 'flow',
        'alternative_intent' => 'invoice',
        'uncertain' => false,
    ],
];
