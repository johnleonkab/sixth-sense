<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\BuildWorkContext;
use App\Models\WorkContext;
use App\WorkContext\WorkContextStatus;
use App\WorkContext\WorkDigest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkContextController extends Controller
{
    /**
     * Whether the nightly context is on, how the last one went and what the assistant knows.
     */
    public function index(Request $request): Response
    {
        $context = $request->user()->workContext ?? new WorkContext;
        $interrupted = $context->status === WorkContextStatus::Running && ! $context->building();
        $status = $interrupted ? WorkContextStatus::Failed : $context->status;

        return Inertia::render('settings/context', [
            'context' => [
                'enabled' => $context->enabled,
                'status' => $status->value,
                'status_label' => $status->label(),
                'error' => match (true) {
                    $interrupted => 'Se interrumpió antes de terminar. Vuelve a generarlo.',
                    $status === WorkContextStatus::Failed => $context->error,
                    default => null,
                },
                'started_at' => $context->started_at?->toIso8601String(),
                'generated_at' => $context->generated_at?->toIso8601String(),
                'usable' => $context->usable(),
                'sources' => $context->sources,
                'digest' => $context->digest ? WorkDigest::fromArray($context->digest)->toArray() : null,
            ],
            'run_at' => config('work_context.run_at'),
        ]);
    }

    /**
     * Switch it on or off. Switching it on builds it now if there is none yet.
     */
    public function update(Request $request): RedirectResponse
    {
        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        $context = $request->user()->workContext()->updateOrCreate([], ['enabled' => $enabled]);

        if ($enabled && $context->generated_at === null && ! $context->building()) {
            BuildWorkContext::dispatch($request->user());
        }

        return back();
    }

    /**
     * Build it again now, without waiting for the night.
     */
    public function build(Request $request): RedirectResponse
    {
        $context = $request->user()->workContext()->first();

        abort_unless($context?->enabled, 409);

        if (! $context->building()) {
            BuildWorkContext::dispatch($request->user());
        }

        return back();
    }
}
