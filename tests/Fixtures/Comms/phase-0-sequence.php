<?php

return [
    'events' => [
        [
            'type' => 'message.received',
            'source' => 'slack',
            'external_id' => 'slack-1',
            'occurred_at' => '2026-11-08T09:00:00+00:00',
            'normalized' => [
                'text' => 'Can you review the prepaid flow before Friday?',
            ],
        ],
    ],
    'projections' => [],
];
