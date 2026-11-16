<?php

return [
    'people' => ['ana' => 'Ana Ruiz'],
    'phases' => [[
        'source' => 'gmail',
        'channel' => 'gmail-cc',
        'messages' => [[
            'name' => 'mail',
            'author' => 'ana',
            'text' => 'El acta de la reunión va adjunta',
            'flags' => ['cc_only' => true],
        ]],
        'segments' => [[
            'messages' => ['mail'],
            'new' => ['mail'],
            'restated' => 'Ana adjunta el acta',
            'link' => ['mode' => 'new_topic', 'quote' => 'El acta de la reunión va adjunta'],
            'operations' => [[
                'op' => 'noise',
                'quote' => 'El acta de la reunión va adjunta',
            ]],
        ]],
    ]],
    'expect' => ['intents' => 0],
];
