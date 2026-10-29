<?php

namespace App\Http\Controllers;

use App\Actions\Actions;
use App\Actions\ActionStatus;
use App\Models\Scene;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The screen's view of a scene, and the single "sí" for every proposal in it.
 */
class SceneController extends Controller
{
    public function show(Request $request, int $scene): JsonResponse
    {
        return response()->json($this->payload($this->find($request, $scene)));
    }

    public function confirm(Request $request, Actions $actions, int $scene): JsonResponse
    {
        $found = $this->find($request, $scene);
        $states = [];

        foreach ($found->steps()->whereNotNull('pending_action_id')->with('action')->get() as $step) {
            $action = $step->action;

            if ($action !== null && $action->status === ActionStatus::Pending) {
                $states[] = $actions->confirm($action)->toState();
            }
        }

        return response()->json(['actions' => $states]);
    }

    private function find(Request $request, int $scene): Scene
    {
        return $request->user()->scenes()->findOrFail($scene);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Scene $scene): array
    {
        return [
            'scene_id' => $scene->id,
            'type' => $scene->type,
            'status' => $scene->status,
            'title' => $scene->canvas['title'] ?? 'Escena',
            'cards' => $scene->canvas['cards'] ?? [],
        ];
    }
}
