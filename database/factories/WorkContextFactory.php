<?php

namespace Database\Factories;

use App\Comms\Context\ContextHarvest;
use App\Comms\Context\ContextWriter;
use App\Comms\Context\FactOrigin;
use App\Models\User;
use App\Models\WorkContext;
use App\WorkContext\WorkContextStatus;
use App\WorkContext\WorkDigest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkContext>
 */
class WorkContextFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'enabled' => true,
            'status' => WorkContextStatus::Idle,
        ];
    }

    /**
     * Read last night: what it found is in the context store.
     *
     * @param  array<string, mixed>  $digest  Replaces parts of the default reading.
     */
    public function ready(array $digest = []): static
    {
        return $this->state(fn (): array => [
            'status' => WorkContextStatus::Ready,
            'sources' => ['slack' => 42, 'gmail' => 30, 'docs' => 5, 'notion' => 3],
            'generated_at' => now(),
        ])->afterCreating(function (WorkContext $context) use ($digest): void {
            app(ContextWriter::class)->record($context->user, FactOrigin::Inferred, ContextHarvest::operations(
                WorkDigest::fromArray([
                    'summary' => 'Esta semana John está cerrando el lanzamiento del prepago con Marta y Pablo, y el contrato con Grupo Vela.',
                    'workstreams' => [['name' => 'Lanzamiento del prepago', 'what' => 'Revisión del flujo antes del 20 de octubre', 'why' => 'Reducir los no-shows', 'with' => ['Marta Ruiz', 'Pablo Gil'], 'where' => ['Slack #producto', 'Notion'], 'next' => 'Ok de Riesgos']],
                    'people' => [['name' => 'Marta Ruiz', 'relation' => 'Product Manager, lleva el prepago']],
                    ...$digest,
                ]),
                CarbonImmutable::now(),
            ));
        });
    }
}
