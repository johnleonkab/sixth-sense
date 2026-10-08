<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Settings\DisplaySize;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DisplayController extends Controller
{
    /**
     * How the assistant looks on screen.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('settings/display', [
            'sizes' => array_map(fn (DisplaySize $size): array => [
                'key' => $size->value,
                'name' => $size->label(),
                'description' => $size->description(),
                'scale' => $size->scale(),
            ], DisplaySize::cases()),
            'current' => $request->user()->display_size->value,
        ]);
    }

    /**
     * Choose how large canvases are shown.
     */
    public function update(Request $request): RedirectResponse
    {
        $size = $request->validate(['display_size' => ['required', Rule::enum(DisplaySize::class)]])['display_size'];

        $request->user()->update(['display_size' => $size]);

        return back();
    }
}
