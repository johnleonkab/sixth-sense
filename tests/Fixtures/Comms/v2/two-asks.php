<?php

return [
    'people' => ['ana' => 'Ana Ruiz'],
    'phases' => [[
        'messages' => [[
            'name' => 'both',
            'author' => 'ana',
            'text' => 'Revisar contrato marzo. Pedir factura proveedor.',
        ]],
        'segments' => [[
            'messages' => ['both'],
            'new' => ['both'],
            'restated' => 'Ana pide dos cosas',
            'link' => ['mode' => 'new_topic', 'quote' => 'Revisar contrato marzo'],
            'operations' => [
                [
                    'op' => 'open',
                    'kind' => 'request',
                    'what' => 'Revisar contrato marzo',
                    'requesters' => ['ana'],
                    'responders' => ['self'],
                    'quote' => 'Revisar contrato marzo',
                ],
                [
                    'op' => 'open',
                    'kind' => 'request',
                    'what' => 'Pedir factura proveedor',
                    'requesters' => ['ana'],
                    'responders' => ['self'],
                    'quote' => 'Pedir factura proveedor',
                ],
            ],
            'critic' => 'holds',
        ]],
    ]],
    'expect' => ['intents' => 2],
];
