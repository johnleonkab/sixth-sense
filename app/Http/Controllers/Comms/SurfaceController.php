<?php

namespace App\Http\Controllers\Comms;

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Surface\HomeBoard;
use App\Comms\Surface\IntentCard;
use App\Http\Controllers\Controller;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsIntent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The v2 home and intent card. JSON for the screen to fetch on its own.
 * Writes go through user.corrected, and the consumer tells the open home.
 */
class SurfaceController extends Controller
{
    public function home(Request $request): JsonResponse
    {
        $this->open();

        return response()->json(HomeBoard::for($request->user()));
    }

    public function intent(Request $request, IntentCard $cards, CommsIntent $intent): JsonResponse
    {
        $this->open();
        $card = $intent->user_id === $request->user()->id ? $cards->open($request->user(), $intent) : null;
        abort_if($card === null, 404);

        return response()->json($card);
    }

    public function topic(Request $request, IntentCard $cards, string $subject): JsonResponse
    {
        $this->open();
        $board = HomeBoard::for($request->user());
        $match = collect($board['topics'])->firstWhere('subject', $subject);
        abort_if($match === null, 404);

        $intents = CommsIntent::query()
            ->whereBelongsTo($request->user())
            ->whereIn('id', $match['intent_ids'])
            ->get()
            ->keyBy('id');
        $shown = [];

        foreach ($match['intent_ids'] as $id) {
            $intent = $intents->get($id);

            if ($intent instanceof CommsIntent) {
                $shown[] = $cards->show($request->user(), $intent);
            }
        }

        return response()->json([
            'subject' => $subject,
            'intents' => $shown,
        ]);
    }

    public function confirm(Request $request, EventLog $log, CommsConversationLink $link): JsonResponse
    {
        $this->open();
        $intent = $this->ownedIntent($request, $link);
        $this->correct($log, $request, $intent, 'confirm_link', ['link_id' => $link->id]);

        return response()->json([
            'card' => app(IntentCard::class)->show($request->user(), $intent->fresh()),
        ]);
    }

    public function move(Request $request, EventLog $log, CommsConversationLink $link): JsonResponse
    {
        $this->open();
        $intent = $this->ownedIntent($request, $link);
        $data = $request->validate([
            'target_id' => ['required', 'integer'],
        ]);
        $target = CommsIntent::query()->whereBelongsTo($request->user())->whereKey($data['target_id'])->first();
        $message = $link->message;
        abort_if($target === null || $message === null || $target->id === $intent->id, 422);

        $this->correct($log, $request, $intent, 'rematch', [
            'target_key' => $target->key,
            'message_source' => $message->source->value,
            'message_external_id' => $message->external_id,
        ]);

        return response()->json([
            'from' => app(IntentCard::class)->show($request->user(), $intent->fresh()),
            'to' => app(IntentCard::class)->show($request->user(), $target->fresh()),
        ]);
    }

    public function notForMe(Request $request, EventLog $log, CommsIntent $intent): JsonResponse
    {
        return $this->intentAction($request, $log, $intent, 'not_for_me');
    }

    public function done(Request $request, EventLog $log, CommsIntent $intent): JsonResponse
    {
        return $this->intentAction($request, $log, $intent, 'done');
    }

    public function stillOpen(Request $request, EventLog $log, CommsIntent $intent): JsonResponse
    {
        return $this->intentAction($request, $log, $intent, 'still_open');
    }

    public function importance(Request $request, EventLog $log, CommsIntent $intent): JsonResponse
    {
        $this->open();
        abort_unless($intent->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'importance' => ['required', 'integer', 'min:0', 'max:100'],
        ]);
        $this->correct($log, $request, $intent, 'importance', ['importance' => (int) $data['importance']]);

        return response()->json([
            'card' => app(IntentCard::class)->show($request->user(), $intent->fresh()),
        ]);
    }

    private function open(): void
    {
        abort_unless(config('comms.linking.surface_v2'), 404);
    }

    private function ownedIntent(Request $request, CommsConversationLink $link): CommsIntent
    {
        $intent = $link->intent;
        abort_if($intent === null || $intent->user_id !== $request->user()->id, 404);

        return $intent;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function intentAction(Request $request, EventLog $log, CommsIntent $intent, string $action): JsonResponse
    {
        $this->open();
        abort_unless($intent->user_id === $request->user()->id, 404);
        $this->correct($log, $request, $intent, $action);

        return response()->json([
            'card' => app(IntentCard::class)->show($request->user(), $intent->fresh()),
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function correct(EventLog $log, Request $request, CommsIntent $intent, string $action, array $extra = []): void
    {
        $log->append(
            user: $request->user(),
            type: EventType::UserCorrected,
            source: Source::User,
            externalId: $action.':'.$intent->key.':'.Str::uuid(),
            payload: null,
            normalized: [
                'action' => $action,
                'intent_key' => $intent->key,
                ...$extra,
            ],
            causation: null,
            occurredAt: now(),
        );
    }
}
