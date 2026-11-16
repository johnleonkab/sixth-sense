<?php

use App\Comms\Jobs\Consuming;
use App\Comms\Models\CompletesTasks;
use App\Comms\Models\ModelCompletion;
use App\Comms\Notify\Silenced;
use App\Comms\People\Relation;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsNotification;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsQuestion;
use App\Models\Comms\CommsWindow;
use App\Models\User;
use Carbon\CarbonImmutable;

afterEach(function () {
    config(['comms.linking.v2' => false]);
    app(Consuming::class)->active = false;
    app(Silenced::class)->active = false;
    CarbonImmutable::setTestNow();
});

test('an unknown user is rejected before any intent is removed', function () {
    $user = User::factory()->create();
    $intent = CommsIntent::factory()->create(['user_id' => $user->id, 'what' => 'Sigue aquí']);

    $this->artisan('comms:rebuild-v2', ['user' => 0])->assertFailed();

    expect($intent->fresh()->what)->toBe('Sigue aquí');
});

test('rebuild keeps the messages, marks new opens, and asks once about what was not reproduced', function () {
    CarbonImmutable::setTestNow('2026-01-15 12:00:00');
    $user = User::factory()->create();
    $other = User::factory()->create();
    $self = CommsPerson::factory()->create([
        'user_id' => $user->id,
        'display_name' => 'Yo',
        'relation' => Relation::Self,
    ]);
    $ana = CommsPerson::factory()->create([
        'user_id' => $user->id,
        'display_name' => 'Ana',
        'relation' => Relation::Collaborator,
    ]);
    $kept = CommsIntent::factory()->create([
        'user_id' => $other->id,
        'what' => 'El intento de otra persona',
    ]);
    CommsIntent::factory()->create([
        'user_id' => $user->id,
        'key' => 'evt-contract',
        'what' => 'Revisar el contrato',
        'state' => 'open',
    ]);
    CommsIntent::factory()->create([
        'user_id' => $user->id,
        'key' => 'evt-demo',
        'what' => 'Preparar la demo',
        'state' => 'open',
    ]);
    CommsNotification::factory()->create([
        'user_id' => $user->id,
        'key' => 'n-old',
        'urgency' => 'now',
    ]);
    $recent = CommsMessage::factory()->create([
        'author_person_id' => $ana->id,
        'channel_ref' => 'C-ask',
        'thread_ref' => null,
        'direction' => 'in',
        'text' => '¿Puedes revisar el contrato?',
        'occurred_at' => CarbonImmutable::now()->subDays(3),
    ]);
    $old = CommsMessage::factory()->create([
        'author_person_id' => $ana->id,
        'channel_ref' => 'C-old',
        'thread_ref' => null,
        'direction' => 'in',
        'text' => 'Una nota de hace tiempo',
        'occurred_at' => CarbonImmutable::now()->subDays(40),
    ]);
    $messages = CommsMessage::query()->whereBelongsTo($user)->count();
    $people = CommsPerson::query()->whereBelongsTo($user)->count();
    $this->app->instance(CompletesTasks::class, rebuildScript($self->id));

    $this->artisan('comms:rebuild-v2', ['user' => $user->id])->assertSuccessful();

    $open = CommsIntent::query()->whereBelongsTo($user)->where('state', 'open')->get();
    $stale = CommsIntent::query()->whereBelongsTo($user)->where('state', 'stale')->get();
    $question = CommsQuestion::query()->whereBelongsTo($user)->first();
    $notifications = CommsNotification::query()->whereBelongsTo($user)->get();

    expect($open)->toHaveCount(1)
        ->and($open->first()->what)->toBe('Revisar el contrato')
        ->and($open->first()->from_backfill)->toBeTrue()
        ->and($stale)->toHaveCount(1)
        ->and($stale->first()->what)->toBe('Preparar la demo')
        ->and($stale->first()->key)->toBe('evt-demo')
        ->and($stale->first()->from_backfill)->toBeFalse()
        ->and($question?->text)->toBe('¿Siguen abiertas?')
        ->and($question?->context['intents'] ?? [])->toBe([['key' => 'evt-demo', 'what' => 'Preparar la demo']])
        ->and($notifications)->toHaveCount(1)
        ->and($notifications->first()->kind)->toBe('batch')
        ->and($notifications->first()->urgency)->toBe('batch')
        ->and(CommsNotification::query()->whereBelongsTo($user)->where('urgency', 'now')->count())->toBe(0)
        ->and(CommsMessage::query()->whereBelongsTo($user)->count())->toBe($messages)
        ->and(CommsPerson::query()->whereBelongsTo($user)->count())->toBe($people)
        ->and($recent->fresh()->window_id)->not->toBeNull()
        ->and($old->fresh()->window_id)->toBeNull()
        ->and(CommsWindow::query()->whereBelongsTo($user)->count())->toBe(1)
        ->and($kept->fresh()->what)->toBe('El intento de otra persona')
        ->and(config('comms.linking.v2'))->toBeFalse()
        ->and(app(Silenced::class)->active)->toBeFalse();
});

function rebuildScript(int $selfId): CompletesTasks
{
    return new class($selfId) implements CompletesTasks
    {
        public function __construct(private int $selfId) {}

        /**
         * @param  array<string, mixed>  $schema
         */
        public function complete(string $provider, string $tier, string $system, string $input, array $schema): ModelCompletion
        {
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

            if (array_key_exists('topics', $properties)) {
                return new ModelCompletion(['topics' => []], 'scripted');
            }

            if (array_key_exists('segments', $properties)) {
                preg_match_all('/<source id="(m\d+)" author="(person:\d+)"[^>]*new="(true|false)"/', $input, $matches, PREG_SET_ORDER);
                $ids = [];
                $fresh = [];
                $people = [];

                foreach ($matches as $match) {
                    $ids[] = $match[1];
                    $people[] = $match[2];

                    if ($match[3] === 'true') {
                        $fresh[] = $match[1];
                    }
                }

                return new ModelCompletion(['segments' => [[
                    'message_ids' => $ids,
                    'new_message_ids' => $fresh,
                    'participants' => array_values(array_unique($people)),
                    'restated' => 'El contrato',
                    'unresolved_refs' => [],
                ]]], 'scripted');
            }

            if (array_key_exists('decision', $properties)) {
                return new ModelCompletion([
                    'decision' => 'new_topic',
                    'name' => 'Contrato',
                    'quote' => '¿Puedes revisar el contrato?',
                    'why' => 'A new ask.',
                ], 'scripted');
            }

            if (array_key_exists('operations', $properties)) {
                return new ModelCompletion(['operations' => [[
                    'op' => 'open',
                    'kind' => 'request',
                    'what' => 'Revisar el contrato',
                    'responders' => ['person:'.$this->selfId],
                    'quote' => '¿Puedes revisar el contrato?',
                ]]], 'scripted');
            }

            if (array_key_exists('real_ask', $properties)) {
                $holds = ['verdict' => 'holds'];

                return new ModelCompletion([
                    'addressed_to_user' => $holds,
                    'already_done' => $holds,
                    'real_ask' => $holds,
                ], 'scripted');
            }

            throw new RuntimeException('Unexpected rebuild schema.');
        }
    };
}
