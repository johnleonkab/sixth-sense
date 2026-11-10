<?php

namespace App\Http\Controllers\Settings;

use App\Comms\Context\Audience;
use App\Comms\Context\ContextEngine;
use App\Comms\Context\ContextHarvest;
use App\Comms\Context\ContextWriter;
use App\Comms\Context\FactOrigin;
use App\Comms\Context\InertiaReview;
use App\Http\Controllers\Controller;
use App\Knowledge\KnowledgeBase;
use App\Models\WorkContext;
use App\Services\PostHogService;
use App\WorkContext\WorkContextStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The one Contexto section: people, areas and hours, what sixth-sense knows,
 * the rules it learned and what it is not sure of. Every edit is an event.
 */
class WorkContextController extends Controller
{
    private const array TABS = ['people', 'areas', 'known', 'rules', 'pending'];

    public function index(Request $request, KnowledgeBase $knowledge, ContextEngine $engine, InertiaReview $inertia): Response
    {
        $user = $request->user();
        $context = $user->workContext ?? new WorkContext;
        $interrupted = $context->status === WorkContextStatus::Running && ! $context->building();
        $status = $interrupted ? WorkContextStatus::Failed : $context->status;
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'people';

        return Inertia::render('settings/context', [
            'tab' => $tab,
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
                'recent' => $context->recent(),
                'sources' => $context->sources,
            ],
            'run_at' => config('work_context.run_at'),
            'knowledge' => $knowledge->status($user),
            ...CommsPeopleController::props($user, $engine),
            'tones' => $engine->listing($user, ['active'], ['preference'], ['tone']),
            'known' => $engine->listing($user, ['active'], null, ['summary', 'state', 'meaning', 'note', 'hot', 'moved']),
            'rules' => array_map(fn (array $rule): array => [
                ...$rule,
                'audience_labels' => array_map(fn (string $audience): string => Audience::tryFrom($audience)?->label() ?? $audience, $rule['audiences']),
            ], $engine->listing($user, ['active'], null, ['rule'])),
            'pending' => $engine->listing($user, ['proposed']),
            'inertia' => $inertia->for($user),
        ]);
    }

    public function reject(Request $request, ContextWriter $context, int $fact): RedirectResponse
    {
        abort_unless($context->reject($request->user(), $fact), 404);

        return back();
    }

    public function accept(Request $request, ContextWriter $context, int $fact): RedirectResponse
    {
        abort_unless($context->accept($request->user(), $fact), 404);

        return back();
    }

    public function edit(Request $request, ContextWriter $context, int $fact): RedirectResponse
    {
        $text = $request->validate(['text' => ['required', 'string', 'max:240']])['text'];

        abort_unless($context->edit($request->user(), $fact, $text), 404);

        return back();
    }

    /**
     * A tone the person writes in, said by them.
     */
    public function tone(Request $request, ContextWriter $context): RedirectResponse
    {
        $data = $request->validate([
            'channel' => ['required', Rule::in(['slack', 'gmail'])],
            'text' => ['required', 'string', 'max:240'],
        ]);

        $context->record($request->user(), FactOrigin::User, [[
            'op' => 'set',
            'kind' => 'preference',
            'subject' => "channel:{$data['channel']}",
            'attribute' => 'tone',
            'value' => ['text' => trim($data['text'])],
            'audiences' => [],
        ]]);

        return back();
    }

    /**
     * Switch the nightly read on or off. Switching it on reads now if it never has.
     */
    public function update(Request $request, PostHogService $posthog): RedirectResponse
    {
        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        $context = $request->user()->workContext()->updateOrCreate([], ['enabled' => $enabled]);

        $posthog->capture((string) $request->user()->getAuthIdentifier(), 'work_context_toggled', [
            'enabled' => $enabled,
        ]);

        if ($enabled && $context->generated_at === null && ! $context->building()) {
            ContextHarvest::dispatch($request->user());
            Log::channel('posthog')->info('work_context_build_dispatched', [
                'trigger' => 'enablement',
            ]);
        }

        return back();
    }

    /**
     * Read again now, without waiting for the night.
     */
    public function build(Request $request, PostHogService $posthog): RedirectResponse
    {
        $context = $request->user()->workContext()->first();

        abort_unless($context?->enabled, 409);

        if (! $context->building()) {
            ContextHarvest::dispatch($request->user());

            $posthog->capture((string) $request->user()->getAuthIdentifier(), 'work_context_build_requested');
            Log::channel('posthog')->info('work_context_build_dispatched', [
                'trigger' => 'manual',
            ]);
        }

        return back();
    }
}
