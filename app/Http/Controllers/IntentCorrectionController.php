<?php

namespace App\Http\Controllers;

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Models\Comms\CommsIntent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * A correction is an event. The consumer applies it.
 */
class IntentCorrectionController extends Controller
{
    public function __invoke(Request $request, EventLog $log, string $key): RedirectResponse
    {
        $user = $request->user();
        $intent = CommsIntent::query()->whereBelongsTo($user)->where('key', $key)->firstOrFail();
        $action = (string) $request->input('action');

        $data = $request->validate([
            'action' => ['required', Rule::in(['close', 'importance', 'still_open', 'rematch', 'merge', 'split'])],
            'importance' => [Rule::requiredIf($action === 'importance'), 'integer', 'min:0', 'max:100'],
            'target_key' => [Rule::requiredIf(in_array($action, ['rematch', 'merge'], true)), 'string'],
            'message_source' => [Rule::requiredIf($action === 'rematch'), 'string'],
            'message_external_id' => [Rule::requiredIf($action === 'rematch'), 'string'],
            'messages' => [Rule::requiredIf($action === 'split'), 'array'],
            'messages.*.source' => ['required', 'string'],
            'messages.*.external_id' => ['required', 'string'],
            'what' => ['nullable', 'string', 'max:280'],
        ]);

        if (isset($data['target_key'])) {
            $ownsTarget = CommsIntent::query()->whereBelongsTo($user)->where('key', $data['target_key'])->exists();
            abort_unless($ownsTarget, 422);
        }

        $log->append(
            user: $user,
            type: EventType::UserCorrected,
            source: Source::User,
            externalId: $data['action'].':'.$intent->key.':'.Str::uuid(),
            payload: null,
            normalized: [
                'action' => $data['action'],
                'intent_key' => $intent->key,
                'importance' => $data['importance'] ?? null,
                'target_key' => $data['target_key'] ?? null,
                'message_source' => $data['message_source'] ?? null,
                'message_external_id' => $data['message_external_id'] ?? null,
                'messages' => $data['messages'] ?? [],
                'what' => $data['what'] ?? ($data['messages'][0]['text'] ?? null),
            ],
            causation: null,
            occurredAt: now(),
        );

        return back();
    }
}
