<?php

namespace App\Http\Controllers\Settings;

use App\Comms\Intents\Archive;
use App\Comms\Intents\PersonRef;
use App\Comms\People\Relation;
use App\Http\Controllers\Controller;
use App\Models\Comms\CommsOwnedArea;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsPreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * People, owned areas and working hours. Every save is a user.configured event.
 */
class CommsPeopleController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $preference = CommsPreference::query()->whereBelongsTo($user)->first();
        $people = new PersonRef;

        return Inertia::render('settings/people', [
            'people' => CommsPerson::query()
                ->whereBelongsTo($user)
                ->where('relation', '!=', Relation::Self)
                ->with('identities')
                ->orderBy('display_name')
                ->get()
                ->map(fn (CommsPerson $person): array => [
                    'id' => $person->id,
                    'name' => $person->display_name,
                    'relation' => $person->relation->value,
                    'weight' => $person->importance_weight,
                    'ref' => $people->key($person),
                ]),
            'areas' => CommsOwnedArea::query()->whereBelongsTo($user)->orderBy('name')->get(['id', 'kind', 'ref', 'name', 'keywords']),
            'preference' => [
                'timezone' => $preference->timezone ?? 'Europe/Madrid',
                'locale' => $preference->locale ?? 'es',
                'work_start' => substr((string) ($preference->work_start ?? '09:00'), 0, 5),
                'work_end' => substr((string) ($preference->work_end ?? '19:00'), 0, 5),
                'work_days' => array_map(intval(...), $preference->work_days ?? [1, 2, 3, 4, 5]),
            ],
            'relations' => collect(Relation::cases())
                ->reject(fn (Relation $relation): bool => $relation === Relation::Self)
                ->map(fn (Relation $relation): array => [
                    'value' => $relation->value,
                    'label' => $this->relationLabel($relation),
                    'weight' => $relation->weight(),
                ])->values(),
            'kinds' => [
                ['value' => 'product', 'label' => 'Producto'],
                ['value' => 'epic', 'label' => 'Epic'],
                ['value' => 'repo', 'label' => 'Repositorio'],
                ['value' => 'jira_project', 'label' => 'Proyecto de Jira'],
                ['value' => 'notion_space', 'label' => 'Espacio de Notion'],
                ['value' => 'channel', 'label' => 'Canal'],
            ],
            'timezones' => [
                'Europe/Madrid',
                'Atlantic/Canary',
                'Europe/London',
                'America/Mexico_City',
                'America/Bogota',
                'America/Argentina/Buenos_Aires',
                'UTC',
            ],
        ]);
    }

    public function update(Request $request, Archive $archive, PersonRef $people, CommsPerson $person): RedirectResponse
    {
        abort_unless($person->user_id === $request->user()->id, 404);
        abort_if($person->relation === Relation::Self, 422);

        $data = $request->validate([
            'relation' => ['required', Rule::enum(Relation::class), Rule::notIn([Relation::Self->value])],
            'importance_weight' => ['nullable', 'integer', 'min:0', 'max:40'],
        ]);

        $normalized = [
            'kind' => 'person',
            'person_ref' => $people->key($person),
            'relation' => $data['relation'],
            'at' => now()->toIso8601String(),
        ];

        if ($request->filled('importance_weight')) {
            $normalized['importance_weight'] = (int) $data['importance_weight'];
        }

        $archive->record($request->user(), $normalized);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Guardado.']);

        return back();
    }

    public function storeArea(Request $request, Archive $archive): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['product', 'epic', 'repo', 'jira_project', 'notion_space', 'channel'])],
            'ref' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:160'],
            'keywords' => ['nullable', 'string', 'max:500'],
        ]);

        $archive->record($request->user(), [
            'kind' => 'area',
            'area_kind' => $data['kind'],
            'ref' => $data['ref'],
            'name' => $data['name'],
            'keywords' => $this->keywords($data['keywords'] ?? ''),
            'at' => now()->toIso8601String(),
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Área guardada.']);

        return back();
    }

    public function destroyArea(Request $request, Archive $archive, CommsOwnedArea $area): RedirectResponse
    {
        abort_unless($area->user_id === $request->user()->id, 404);

        $archive->record($request->user(), [
            'kind' => 'area',
            'area_kind' => $area->kind,
            'ref' => $area->ref,
            'name' => $area->name,
            'removed' => true,
            'at' => now()->toIso8601String(),
        ]);

        return back();
    }

    public function updatePreference(Request $request, Archive $archive): RedirectResponse
    {
        $data = $request->validate([
            'timezone' => ['required', 'timezone'],
            'locale' => ['required', Rule::in(['es', 'en'])],
            'work_start' => ['required', 'date_format:H:i'],
            'work_end' => ['required', 'date_format:H:i'],
            'work_days' => ['required', 'array', 'min:1'],
            'work_days.*' => ['integer', Rule::in([1, 2, 3, 4, 5, 6, 7])],
        ]);

        $archive->record($request->user(), [
            'kind' => 'preference',
            'timezone' => $data['timezone'],
            'locale' => $data['locale'],
            'work_start' => $data['work_start'],
            'work_end' => $data['work_end'],
            'work_days' => array_values($data['work_days']),
            'at' => now()->toIso8601String(),
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Horario guardado.']);

        return back();
    }

    private function relationLabel(Relation $relation): string
    {
        return match ($relation) {
            Relation::Boss => 'Responsable',
            Relation::Report => 'De mi equipo',
            Relation::Collaborator => 'Colaboración',
            Relation::Stakeholder => 'Interesado',
            Relation::Client => 'Cliente',
            Relation::Vendor => 'Proveedor',
            Relation::Other => 'Otra',
            Relation::Unknown => 'Sin clasificar',
            Relation::Self => 'Yo',
        };
    }

    /**
     * @return list<string>
     */
    private function keywords(string $raw): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', $raw))));
    }
}
