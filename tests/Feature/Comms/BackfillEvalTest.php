<?php

use App\Comms\Events\Source;
use App\Comms\Jobs\Consuming;
use App\Comms\Models\CompletesTasks;
use App\Comms\Models\ModelCompletion;
use App\Comms\People\Relation;
use App\Models\Comms\CommsEvalRun;
use App\Models\Comms\CommsGoldenLabel;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

afterEach(function () {
    config([
        'comms.linking.v2' => false,
        'comms.tasks.critic.provider' => 'gemini',
        'comms.tasks.critic.fallback' => 'deepseek',
    ]);
    app(Consuming::class)->active = false;
    CarbonImmutable::setTestNow();

    $directory = storage_path('framework/testing/evals');

    if (is_dir($directory)) {
        File::deleteDirectory($directory);
    }
});

test('an unknown user is rejected before an evaluation user or report exists', function () {
    $users = User::query()->count();

    $this->artisan('comms:backfill-eval', ['user' => 0])->assertFailed();

    expect(User::query()->count())->toBe($users)
        ->and(File::isDirectory(storage_path('framework/testing/evals')))->toBeFalse();
});

test('an unknown critic is rejected before an evaluation user or report exists', function () {
    $user = User::factory()->create();
    $users = User::query()->count();

    $this->artisan('comms:backfill-eval', ['user' => $user->id, '--critic' => 'claude'])->assertFailed();

    expect(User::query()->count())->toBe($users)
        ->and(File::isDirectory(storage_path('framework/testing/evals')))->toBeFalse();
});

test('an empty window still writes a report with empty rates', function () {
    CarbonImmutable::setTestNow('2026-01-15 12:00:00');
    config(['comms.evaluation.directory' => storage_path('framework/testing/evals')]);
    $user = User::factory()->create();
    CommsMessage::factory()->create([
        'author_person_id' => CommsPerson::factory()->create(['user_id' => $user->id])->id,
        'text' => 'Fuera de la ventana',
        'occurred_at' => CarbonImmutable::now()->subDays(40),
    ]);

    $this->artisan('comms:backfill-eval', ['user' => $user->id])->assertSuccessful();

    $report = evalReport($user);

    expect($report['needs_user']['precision'])->toBeNull()
        ->and($report['needs_user']['recall'])->toBeNull()
        ->and($report['uncertain_share'])->toBeNull()
        ->and($report['critic']['rate'])->toBeNull()
        ->and($report['tokens_per_window'])->toBeNull()
        ->and($report['cost_per_window'])->toBeNull()
        ->and($report['latency_ms']['windows'])->toBe(0)
        ->and($report['latency_ms']['p50'])->toBeNull()
        ->and($report['latency_ms']['p95'])->toBeNull()
        ->and($report['retrieval'])->toBe([
            'topic' => ['hits' => 0, 'total' => 0],
            'intent' => ['hits' => 0, 'total' => 0],
        ])
        ->and(CommsMessage::query()->whereBelongsTo($user)->count())->toBe(1)
        ->and(config('comms.linking.v2'))->toBeFalse();
});

test('the last nine days are scored against acted and ignored without touching the source', function () {
    CarbonImmutable::setTestNow('2026-01-15 12:00:00');
    config(['comms.evaluation.directory' => storage_path('framework/testing/evals')]);
    $source = User::factory()->create();
    $other = User::factory()->create();
    $self = CommsPerson::factory()->create([
        'user_id' => $source->id,
        'display_name' => 'Yo',
        'relation' => Relation::Self,
    ]);
    $ana = CommsPerson::factory()->create([
        'user_id' => $source->id,
        'display_name' => 'Ana',
        'relation' => Relation::Collaborator,
    ]);
    $ana->identities()->create([
        'user_id' => $source->id,
        'source' => Source::Slack,
        'external_id' => 'UANA',
        'email' => 'ana@example.com',
        'handle' => 'ana',
    ]);
    $kept = CommsIntent::factory()->create([
        'user_id' => $source->id,
        'what' => 'Un intento que ya estaba',
    ]);
    evalMessage($ana, 'C-early', 'in', 'Notas de la semana pasada', 20);
    evalMessage($ana, 'C-old', 'in', 'Fuera de la ventana', 40);
    evalMessage($ana, 'C-ask', 'in', '¿Puedes revisar el contrato?', 3);
    evalMessage($self, 'C-ask', 'out', 'Ya lo miro', 2);
    evalMessage($ana, 'C-other', 'in', 'El acta va adjunta', 1);
    $dropped = evalMessage($ana, 'C-gone', 'in', 'Borrado', 2);
    $dropped->deleted_at = CarbonImmutable::now();
    $dropped->save();
    evalMessage(
        CommsPerson::factory()->create(['user_id' => $other->id, 'display_name' => 'Otro']),
        'C-other',
        'in',
        'Mensaje de otra persona',
        2,
    );
    $messages = CommsMessage::query()->whereBelongsTo($source)->count();
    $fake = evalScript();
    $this->app->instance(CompletesTasks::class, $fake);

    $this->artisan('comms:backfill-eval', ['user' => $source->id])->assertSuccessful();

    $report = evalReport($source);
    $eval = User::query()->findOrFail($report['eval_user_id']);
    $labels = CommsGoldenLabel::query()->whereBelongsTo($eval)->get()->mapWithKeys(function (CommsGoldenLabel $label): array {
        $text = (string) CommsMessage::query()->find($label->message_id)?->text;

        return [$text => $label->label];
    })->all();
    $topic = collect($fake->calls)->first(fn (array $call): bool => array_key_exists('topics', $fake->properties($call['schema'])));
    $critic = collect($fake->calls)->filter(fn (array $call): bool => array_key_exists('real_ask', $fake->properties($call['schema'])));

    expect($report['critic_provider'])->toBe('gemini')
        ->and($report['passed'])->toBeNull()
        ->and($report['prompt_versions'])->toBe([
            'segment' => config('comms.tasks.segment.prompt'),
            'link' => config('comms.tasks.link.prompt'),
            'extract' => config('comms.tasks.extract.prompt'),
            'critic' => config('comms.tasks.critic.prompt'),
            'topic_build' => config('comms.tasks.topic_build.prompt'),
            'identity_resolve' => config('comms.tasks.identity_resolve.prompt'),
        ])
        ->and($report['retrieval'])->toBe([
            'topic' => ['hits' => 0, 'total' => 0],
            'intent' => ['hits' => 0, 'total' => 0],
        ])
        ->and($report['uncertain_share'])->toEqual(0)
        ->and($report['needs_user'])->toEqual([
            'tp' => 1,
            'fp' => 0,
            'fn' => 0,
            'tn' => 1,
            'precision' => 1.0,
            'recall' => 1.0,
        ])
        ->and($report['critic'])->toEqual(['refuted' => 0, 'checked' => 1, 'rate' => 0.0])
        ->and($report['tokens_per_window'])->toEqual(72)
        ->and($report['cost_per_window'])->toEqual(0.06)
        ->and($report['latency_ms']['windows'])->toBe(2)
        ->and($report['latency_ms']['p50'])->toBeGreaterThanOrEqual(0)
        ->and($report['latency_ms']['p95'])->toBeGreaterThanOrEqual($report['latency_ms']['p50'])
        ->and($labels)->toMatchArray([
            'Notas de la semana pasada' => 'ignored',
            '¿Puedes revisar el contrato?' => 'acted',
            'El acta va adjunta' => 'ignored',
        ])
        ->and(CommsMessage::query()->whereBelongsTo($eval)->orderBy('occurred_at')->pluck('text')->all())->toBe([
            'Notas de la semana pasada',
            '¿Puedes revisar el contrato?',
            'Ya lo miro',
            'El acta va adjunta',
        ])
        ->and(CommsPerson::query()->whereBelongsTo($eval)->orderBy('id')->pluck('display_name')->all())->toBe(['Yo', 'Ana'])
        ->and(CommsIntent::query()->whereBelongsTo($eval)->count())->toBe(1)
        ->and(CommsIntent::query()->whereBelongsTo($eval)->value('what'))->toBe('Revisar el contrato')
        ->and(CommsMessage::query()->whereBelongsTo($source)->count())->toBe($messages)
        ->and(CommsIntent::query()->whereBelongsTo($source)->count())->toBe(1)
        ->and($kept->fresh()->what)->toBe('Un intento que ya estaba')
        ->and(CommsMessage::query()->whereBelongsTo($other)->count())->toBe(1)
        ->and(CommsGoldenLabel::query()->whereBelongsTo($source)->count())->toBe(0)
        ->and($topic)->not->toBeNull()
        ->and($topic['input'])->not->toContain('contrato')
        ->and($topic['input'])->not->toContain('acta')
        ->and($critic)->toHaveCount(1)
        ->and($critic->first()['provider'])->toBe('gemini')
        ->and(config('comms.linking.v2'))->toBeFalse()
        ->and(config('comms.tasks.critic.provider'))->toBe('gemini');
});

test('the critic comparison uses deepseek for that run only', function () {
    CarbonImmutable::setTestNow('2026-01-15 12:00:00');
    config(['comms.evaluation.directory' => storage_path('framework/testing/evals')]);
    $user = User::factory()->create();
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
    evalMessage($ana, 'C-ask', 'in', '¿Puedes revisar el contrato?', 1);
    $fake = evalScript();
    $this->app->instance(CompletesTasks::class, $fake);

    $this->artisan('comms:backfill-eval', ['user' => $user->id, '--critic' => 'deepseek'])->assertSuccessful();

    $report = evalReport($user);
    $critic = collect($fake->calls)->first(fn (array $call): bool => array_key_exists('real_ask', $fake->properties($call['schema'])));

    expect($report['critic_provider'])->toBe('deepseek')
        ->and($report['needs_user']['precision'])->toEqual(0)
        ->and($report['needs_user']['recall'])->toBeNull()
        ->and($critic['provider'])->toBe('deepseek')
        ->and(config('comms.tasks.critic.provider'))->toBe('gemini')
        ->and(config('comms.tasks.critic.fallback'))->toBe('deepseek')
        ->and(CommsPerson::query()->whereBelongsTo(User::query()->findOrFail($report['eval_user_id']))->where('relation', Relation::Self)->value('display_name'))->toBe($self->display_name);
});

function evalMessage(CommsPerson $author, string $channel, string $direction, string $text, int $daysAgo): CommsMessage
{
    return CommsMessage::factory()->create([
        'author_person_id' => $author->id,
        'channel_ref' => $channel,
        'thread_ref' => null,
        'direction' => $direction,
        'text' => $text,
        'occurred_at' => CarbonImmutable::now()->subDays($daysAgo),
    ]);
}

/**
 * @return array<string, mixed>
 */
function evalReport(User $user): array
{
    $path = (string) CommsEvalRun::query()->whereBelongsTo($user)->value('report_path');

    expect($path)->not->toBe('')
        ->and(File::exists($path))->toBeTrue();

    $report = json_decode((string) File::get($path), true);

    expect($report)->toBeArray()
        ->and(CommsEvalRun::query()->whereBelongsTo($user)->first())
        ->passed->toBeNull()
        ->kind->toBe('backfill')
        ->user_id->toBe($user->id);

    return $report;
}

function evalScript(): object
{
    return new class implements CompletesTasks
    {
        /**
         * @var list<array{provider: string, tier: string, system: string, input: string, schema: array<string, mixed>}>
         */
        public array $calls = [];

        /**
         * @param  array<string, mixed>  $schema
         */
        public function complete(string $provider, string $tier, string $system, string $input, array $schema): ModelCompletion
        {
            $this->calls[] = compact('provider', 'tier', 'system', 'input', 'schema');

            return new ModelCompletion($this->body($input, $schema), 'scripted', 10, 2, 1, 0.01);
        }

        /**
         * @param  array<string, mixed>  $schema
         * @return array<string, mixed>
         */
        public function properties(array $schema): array
        {
            $properties = $schema['properties'] ?? [];

            return is_array($properties) ? $properties : [];
        }

        /**
         * @param  array<string, mixed>  $schema
         * @return array<string, mixed>
         */
        private function body(string $input, array $schema): array
        {
            $properties = $this->properties($schema);

            if (array_key_exists('topics', $properties)) {
                return ['topics' => []];
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

                return ['segments' => [[
                    'message_ids' => $ids,
                    'new_message_ids' => $fresh,
                    'participants' => array_values(array_unique($people)),
                    'restated' => 'El contrato',
                    'unresolved_refs' => [],
                ]]];
            }

            if (array_key_exists('decision', $properties)) {
                if (str_contains($input, 'El acta va adjunta')) {
                    return ['decision' => 'none', 'quote' => 'El acta va adjunta', 'why' => 'Nothing to track.'];
                }

                return [
                    'decision' => 'new_topic',
                    'name' => 'Contrato',
                    'quote' => '¿Puedes revisar el contrato?',
                    'why' => 'A new ask.',
                ];
            }

            if (array_key_exists('operations', $properties)) {
                $eval = User::query()->orderByDesc('id')->first();
                $self = CommsPerson::query()->whereBelongsTo($eval)->where('relation', Relation::Self)->first();

                return ['operations' => [[
                    'op' => 'open',
                    'kind' => 'request',
                    'what' => 'Revisar el contrato',
                    'responders' => ['person:'.$self->id],
                    'quote' => '¿Puedes revisar el contrato?',
                ]]];
            }

            if (array_key_exists('real_ask', $properties)) {
                $holds = ['verdict' => 'holds'];

                return [
                    'addressed_to_user' => $holds,
                    'already_done' => $holds,
                    'real_ask' => $holds,
                ];
            }

            throw new RuntimeException('Unexpected evaluation schema.');
        }
    };
}
