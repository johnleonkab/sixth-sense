<?php

namespace App\Http\Controllers\Settings;

use App\Comms\Context\Audience;
use App\Comms\Context\ContextEngine;
use App\Comms\Context\ContextHarvest;
use App\Comms\Context\ContextWriter;
use App\Comms\Context\FactOrigin;
use App\Comms\Context\InertiaReview;
use App\Comms\Events\Source;
use App\Comms\Intents\Archive;
use App\Comms\Intents\PersonRef;
use App\Comms\Nightly\NightChanges;
use App\Comms\Topics\TopicReview;
use App\Comms\Windows\UrgentRules;
use App\Connectors\Chat\Chats;
use App\Http\Controllers\Controller;
use App\Knowledge\KnowledgeBase;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsScope;
use App\Models\Comms\CommsUrgentRule;
use App\Models\User;
use App\Models\WorkContext;
use App\Services\PostHogService;
use App\WorkContext\WorkContextStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The one Contexto section: people, areas and hours, what sixth-sense knows,
 * the rules it learned and what it is not sure of. Every edit is an event.
 */
class WorkContextController extends Controller
{
    private const array TABS = ['people', 'topics', 'areas', 'known', 'rules', 'pending', 'channels', 'urgent', 'changes'];

    public function index(Request $request, KnowledgeBase $knowledge, ContextEngine $engine, InertiaReview $inertia, Chats $chats): Response
    {
        $user = $request->user();
        $context = $user->workContext ?? new WorkContext;
        $interrupted = $context->status === WorkContextStatus::Running && ! $context->building();
        $status = $interrupted ? WorkContextStatus::Failed : $context->status;
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'people';
        $channels = in_array($tab, ['channels', 'urgent'], true) ? $this->listedChannels($user, $chats) : [];

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
            'channels' => $channels,
            'urgent_rules' => $tab === 'urgent' ? $this->listedUrgent($user, $channels) : [],
            'topics' => $tab === 'topics' ? app(TopicReview::class)->list($user) : [],
            'night_changes' => $tab === 'changes' ? app(NightChanges::class)->screen($user) : ['lines' => [], 'classes' => []],
        ]);
    }

    /**
     * Extra Slack channels to follow in full. The list replaces the previous one.
     */
    public function channels(Request $request, Archive $archive): RedirectResponse
    {
        $ids = $request->validate([
            'slack_channel_ids' => ['present', 'array'],
            'slack_channel_ids.*' => ['string', 'max:64'],
        ])['slack_channel_ids'];

        $archive->record($request->user(), [
            'kind' => 'scope',
            'slack_channel_ids' => array_values($ids),
        ]);

        return back();
    }

    /**
     * One urgent rule. Saving again with the same kind and ref updates it,
     * which is how the activo toggle turns a rule off without deleting it.
     */
    public function urgent(Request $request, Archive $archive): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['person', 'keyword', 'channel', 'person_weight'])],
            'ref' => ['required', 'string', 'max:191'],
            'active' => ['sometimes', 'boolean'],
            'sources' => ['sometimes', 'array'],
            'sources.*' => ['string', 'max:32'],
        ]);

        $archive->record($request->user(), [
            'kind' => 'urgent_rule',
            'op' => 'set',
            'rule' => [
                'kind' => $data['kind'],
                'ref' => trim($data['ref']),
                'sources' => array_values($data['sources'] ?? []),
                'active' => $request->boolean('active', true),
            ],
        ]);

        return back();
    }

    public function removeUrgent(Request $request, Archive $archive): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['person', 'keyword', 'channel', 'person_weight'])],
            'ref' => ['required', 'string', 'max:191'],
        ]);

        $archive->record($request->user(), [
            'kind' => 'urgent_rule',
            'op' => 'remove',
            'rule' => [
                'kind' => $data['kind'],
                'ref' => trim($data['ref']),
                'sources' => [],
                'active' => true,
            ],
        ]);

        return back();
    }

    /**
     * How many recent messages a keyword would have caught, before it is saved.
     */
    public function previewUrgent(Request $request, UrgentRules $rules): JsonResponse
    {
        $keyword = $request->validate([
            'keyword' => ['required', 'string', 'max:80'],
        ])['keyword'];

        return response()->json($rules->keywordPreview($request->user(), $keyword));
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
     * Channels the account belongs to, with how much arrived in the last 30
     * days and whether the user already posts there.
     *
     * @return list<array{id: string, name: string, members: int, messages: int, included: bool, selected: bool}>
     */
    private function listedChannels(User $user, Chats $chats): array
    {
        try {
            $listed = $chats->channels($user);
        } catch (Throwable $exception) {
            report($exception);
            $listed = [];
        }

        $since = now()->subDays(30);
        $counts = CommsMessage::query()
            ->whereBelongsTo($user)
            ->where('source', Source::Slack)
            ->where('occurred_at', '>=', $since)
            ->whereNull('deleted_at')
            ->selectRaw("channel_ref, count(*) as total, sum(case when direction = 'out' then 1 else 0 end) as posted")
            ->groupBy('channel_ref')
            ->get()
            ->keyBy(fn (CommsMessage $row): string => (string) $row->getRawOriginal('channel_ref'));
        $selected = CommsScope::query()->whereBelongsTo($user)->first()->slack_channel_ids ?? [];

        return array_map(function (array $channel) use ($counts, $selected): array {
            $row = $counts->get($channel['id']);

            return [
                'id' => $channel['id'],
                'name' => $channel['name'],
                'members' => $channel['members'],
                'messages' => (int) ($row?->getRawOriginal('total') ?? 0),
                'included' => (int) ($row?->getRawOriginal('posted') ?? 0) > 0,
                'selected' => in_array($channel['id'], $selected, true),
            ];
        }, $listed);
    }

    /**
     * Rules already saved, with a name the screen can show. The default
     * rule is created here the first time the tab is opened.
     *
     * @param  list<array{id: string, name: string, members: int, messages: int, included: bool, selected: bool}>  $channels
     * @return list<array{kind: string, ref: string, sources: list<string>, active: bool, label: string}>
     */
    private function listedUrgent(User $user, array $channels): array
    {
        $people = new PersonRef;
        $names = [];

        foreach ($channels as $channel) {
            $names[$channel['id']] = $channel['name'];
        }

        return app(UrgentRules::class)->all($user)->map(function (CommsUrgentRule $rule) use ($user, $people, $names): array {
            return [
                'kind' => $rule->kind,
                'ref' => $rule->ref,
                'sources' => array_values($rule->sources ?? []),
                'active' => $rule->active,
                'label' => $this->urgentLabel($user, $people, $names, $rule),
            ];
        })->all();
    }

    /**
     * @param  array<string, string>  $channelNames
     */
    private function urgentLabel(User $user, PersonRef $people, array $channelNames, CommsUrgentRule $rule): string
    {
        if ($rule->kind === 'person_weight') {
            return "Mensaje directo de alguien con peso {$rule->ref} o más";
        }

        if ($rule->kind === 'person') {
            return $people->find($user, $rule->ref)?->display_name ?? $rule->ref;
        }

        if ($rule->kind === 'channel') {
            $id = str_contains($rule->ref, ':') ? substr($rule->ref, (int) strpos($rule->ref, ':') + 1) : $rule->ref;

            return '#'.($channelNames[$id] ?? $id);
        }

        return $rule->ref;
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
