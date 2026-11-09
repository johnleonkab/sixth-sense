<?php

return [
    'events' => [
        [
            'type' => 'message.received',
            'source' => 'gmail',
            'external_id' => 'gmail-1',
            'occurred_at' => '2026-11-08T09:05:00+00:00',
            'normalized' => [
                'text' => 'Following up on the prepaid flow.',
            ],
        ],
    ],
    'projections' => [
        'intents' => [
            ['what' => 'Review the prepaid flow'],
        ],
    ],
];
