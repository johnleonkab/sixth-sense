<?php

namespace App\Http\Controllers\Settings;

use App\Analytics\Impact;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ImpactController extends Controller
{
    /** Periods to look at, in days (null: since the start). */
    private const PERIODS = ['7' => 7, '30' => 30, 'all' => null];

    private const WEEKS = 8;

    /**
     * What sixth-sense saved the person.
     */
    public function index(Request $request, Impact $impact): Response
    {
        $period = $request->validate(['period' => ['nullable', Rule::in(array_keys(self::PERIODS))]])['period'] ?? '30';
        $days = self::PERIODS[$period];
        $timezone = (string) config('agenda.timezone');

        return Inertia::render('settings/impact', [
            'period' => $period,
            'summary' => $impact->summary($request->user(), $days === null ? null : CarbonImmutable::now($timezone)->subDays($days)),
            'weekly' => $impact->weekly($request->user(), self::WEEKS, $timezone),
            'rates' => ['proposal' => Impact::MINUTES_PER_PROPOSAL, 'slot' => Impact::MINUTES_PER_SLOT],
        ]);
    }
}
