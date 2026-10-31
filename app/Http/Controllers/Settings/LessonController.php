<?php

namespace App\Http\Controllers\Settings;

use App\Alerts\Lessons\InertiaReview;
use App\Http\Controllers\Controller;
use App\Models\IntentLesson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LessonController extends Controller
{
    /**
     * What sixth-sense learned from the person's corrections, to correct or
     * delete, and, when nearly everything goes out untouched, a few sends to
     * look at again.
     */
    public function index(Request $request, InertiaReview $inertia): Response
    {
        return Inertia::render('settings/lessons', [
            'lessons' => $request->user()->intentLessons()->latest('id')->limit(200)->get()->map(fn (IntentLesson $lesson): array => [
                'id' => $lesson->id,
                'body' => $lesson->body,
                'layer' => $lesson->layer,
                'layer_label' => IntentLesson::LAYERS[$lesson->layer] ?? 'General',
                'about' => $lesson->about,
                'uses' => $lesson->uses,
                'created_at' => $lesson->created_at->toIso8601String(),
            ])->all(),
            'inertia' => $inertia->for($request->user()),
        ]);
    }

    public function update(Request $request, int $lesson): RedirectResponse
    {
        $request->user()->intentLessons()->findOrFail($lesson)->update($request->validate(['body' => ['required', 'string', 'max:240']]));

        return back();
    }

    public function destroy(Request $request, int $lesson): RedirectResponse
    {
        $request->user()->intentLessons()->findOrFail($lesson)->delete();

        return back();
    }
}
