<?php

namespace App\Comms\Context;

use App\Models\Comms\CommsIntent;

final readonly class ContextRequest
{
    /**
     * @param  list<string>  $subjects  Facts about these come first: `person:{ref}`, `channel:slack:{name}`…
     * @param  ?string  $about  A name, when the subject is only known by how it is written.
     * @param  ?CommsIntent  $intent  Adds the intent's conversation and the other open intents of its people.
     * @param  ?string  $query  Adds what the corpus says about it.
     * @param  ?string  $callRef  The model call that receives the pack, kept with each fact used.
     */
    public function __construct(
        public Audience $audience,
        public array $subjects = [],
        public ?string $about = null,
        public ?CommsIntent $intent = null,
        public ?string $query = null,
        public ?string $callRef = null,
    ) {}
}
