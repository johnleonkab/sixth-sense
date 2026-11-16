<?php

/*
| The boundaries the communications pipeline depends on. Reading never
| reaches a write path, and a playbook can only propose.
*/

arch('ingestion never reaches the gate or a write tool')
    ->expect('App\Comms\Ingestion')
    ->not->toUse(['App\Comms\Gate', 'App\Actions', 'App\Connectors\Chat\Chats', 'App\Connectors\Mail\Outbox']);

arch('playbooks only propose, they never call a write tool')
    ->expect('App\Comms\Playbooks')
    ->not->toUse('App\Comms\Gate\WriteTools');
