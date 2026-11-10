<?php

namespace App\Console\Commands;

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Ingestion\Ingestor;
use App\Comms\Ingestion\SlackInbound;
use App\Comms\Messages\Party;
use App\Models\AiUsage;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsNotification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Sends one fake Slack message, or a fresh copy of past ones, through the
 * live pipeline and the real queue, then prints what each step decided.
 * Copies get a new message id, so the original stays as it was. Nothing is
 * sent to Slack: replies are still proposals that wait for a yes.
 */
#[Signature('comms:probe-slack
    {user : The user id}
    {text? : A fake message, as if someone wrote it to the user on Slack}
    {--from=Prueba sixth-sense : Who the fake message comes from}
    {--in=dm : Where the fake message is written: dm, group or channel}
    {--rerun=0 : Send again the last N Slack messages the user received}
    {--event=* : Send again these Slack event ids}
    {--wait=90 : Seconds to wait for the queue to process them}')]
#[Description('Run a fake or a past Slack message through the communications pipeline and show what it did')]
class CommsProbeSlack extends Command
{
    public function handle(Ingestor $ingestor, EventLog $log): int
    {
        $user = User::query()->find($this->argument('user'));

        if (! $user instanceof User) {
            $this->components->error('User not found.');

            return self::FAILURE;
        }

        if (! (bool) config('comms.pipeline.slack')) {
            $this->components->warn('COMMS_PIPELINE_SLACK is off: the messages are stored but not triaged.');
        }

        $text = trim((string) $this->argument('text'));
        $events = $text !== '' ? array_filter([$this->fake($user, $ingestor, $text)]) : $this->rerun($user, $log);

        if ($events === []) {
            $this->components->error($text !== ''
                ? 'The fake message is out of scope.'
                : 'Nothing to send: pass a text, --rerun=N or --event=ID.');

            return self::FAILURE;
        }

        if (! $this->waitFor($events)) {
            $earlier = CommsEvent::query()
                ->whereBelongsTo($user)
                ->whereNull('processed_at')
                ->where('id', '<', min(array_map(fn (CommsEvent $event): int => $event->id, $events)))
                ->count();

            $this->components->warn('Not processed after '.(int) $this->option('wait').' s. Is Horizon running the comms-decide queue?'
                .($earlier > 0 ? " {$earlier} earlier events of this user are still unprocessed, and each event waits for them." : ''));
        }

        foreach ($events as $event) {
            $this->report($user, $event->refresh());
        }

        return self::SUCCESS;
    }

    private function fake(User $user, Ingestor $ingestor, string $text): ?CommsEvent
    {
        $kind = in_array($this->option('in'), ['dm', 'group', 'channel'], true) ? (string) $this->option('in') : 'dm';
        $from = (string) $this->option('from');
        $at = CarbonImmutable::now();

        return $ingestor->ingest($user, new SlackInbound(
            teamId: 'probe',
            channelId: 'probe-'.$kind,
            ts: sprintf('%d.%06d', $at->timestamp, $at->micro),
            channelKind: $kind,
            author: new Party('probe-'.Str::slug($from), null, $from),
            recipients: [],
            text: $text,
            at: $at,
            channelAllowed: true,
        ));
    }

    /**
     * @return list<CommsEvent>
     */
    private function rerun(User $user, EventLog $log): array
    {
        $ids = array_map(intval(...), (array) $this->option('event'));
        $count = (int) $this->option('rerun');

        if ($ids === [] && $count < 1) {
            return [];
        }

        $originals = CommsEvent::query()
            ->whereBelongsTo($user)
            ->where('source', Source::Slack)
            ->where('type', EventType::MessageReceived)
            ->when($ids !== [], fn ($query) => $query->whereIn('id', $ids))
            ->when($ids === [], fn ($query) => $query->where('external_id', 'not like', '%:rerun-%')->latest('id')->limit($count))
            ->get()
            ->sortBy('id');

        $suffix = ':rerun-'.now()->format('YmdHisv');
        $now = CarbonImmutable::now();

        return $originals->map(function (CommsEvent $original) use ($user, $log, $suffix, $now): CommsEvent {
            $normalized = [
                ...$original->normalized ?? [],
                'external_id' => $original->external_id.$suffix,
                'at' => $now->toIso8601String(),
                'backfill' => false,
                'rerun_of' => $original->id,
            ];

            return $log->append(
                user: $user,
                type: EventType::MessageReceived,
                source: Source::Slack,
                externalId: $original->external_id.$suffix,
                payload: null,
                normalized: $normalized,
                causation: null,
                occurredAt: $now,
            );
        })->values()->all();
    }

    /**
     * @param  list<CommsEvent>  $events
     */
    private function waitFor(array $events): bool
    {
        $ids = array_map(fn (CommsEvent $event): int => $event->id, $events);
        $deadline = now()->addSeconds((int) $this->option('wait'));

        while (CommsEvent::query()->whereIn('id', $ids)->whereNull('processed_at')->exists()) {
            if (now()->greaterThan($deadline)) {
                return false;
            }

            Sleep::for(1)->second();
        }

        return true;
    }

    private function report(User $user, CommsEvent $event): void
    {
        $normalized = $event->normalized ?? [];
        $this->newLine();
        $this->components->twoColumnDetail(
            "<fg=cyan>#{$event->id}</> ".($normalized['author']['display_name'] ?? '?'),
            Str::limit((string) ($normalized['text'] ?? ''), 70),
        );

        if ($event->processed_at === null) {
            $this->components->twoColumnDetail('status', '<fg=yellow>waiting in the queue</>');

            return;
        }

        $trace = $this->trace($user, $event);

        foreach (AiUsage::query()->where('user_id', $user->id)->whereJsonContains('event_ids', $event->id)->oldest('id')->get() as $usage) {
            $this->components->twoColumnDetail(
                "model {$usage->task} ({$usage->tier}, {$usage->model})",
                $usage->status === 'ok' ? '<fg=green>ok</>' : "<fg=red>{$usage->status}</> ".Str::limit((string) ($usage->error ?? json_encode($usage->output, JSON_UNESCAPED_UNICODE)), 80),
            );
        }

        foreach ($trace as $step) {
            $this->components->twoColumnDetail($step->type->value, $this->describe($step));
        }

        $message = CommsMessage::query()->where('event_id', $event->id)->first();
        $links = $message === null ? collect() : CommsConversationLink::query()->with('intent')->where('message_id', $message->id)->get();

        foreach ($links as $link) {
            $this->components->twoColumnDetail(
                "intent {$link->operation}",
                $link->intent === null ? '?' : "{$link->intent->what} [{$link->intent->state}, importance {$link->intent->importance}]",
            );
        }

        $notifications = CommsNotification::query()
            ->whereBelongsTo($user)
            ->where(function ($query) use ($event, $trace): void {
                foreach ([$event->id, ...array_map(fn (CommsEvent $step): int => $step->id, $trace)] as $id) {
                    $query->orWhereJsonContains('source_event_ids', $id);
                }
            })
            ->get();

        foreach ($notifications as $notification) {
            $this->components->twoColumnDetail("notification {$notification->kind}", "{$notification->urgency}".($notification->delivered_at !== null ? ', delivered' : ''));
        }

        $this->components->twoColumnDetail('<options=bold>alert</>', $notifications->isNotEmpty() ? '<fg=green>yes</>' : '<fg=yellow>no</>');
    }

    /**
     * Events this one caused, and the ones those caused, in order.
     *
     * @return list<CommsEvent>
     */
    private function trace(User $user, CommsEvent $event): array
    {
        $trace = [];
        $frontier = [$event->id];

        for ($depth = 0; $depth < 5 && $frontier !== []; $depth++) {
            $children = CommsEvent::query()->whereBelongsTo($user)->whereIn('causation_id', $frontier)->oldest('id')->get();
            $trace = [...$trace, ...$children->all()];
            $frontier = $children->pluck('id')->all();
        }

        usort($trace, fn (CommsEvent $left, CommsEvent $right): int => $left->id <=> $right->id);

        return $trace;
    }

    private function describe(CommsEvent $step): string
    {
        $normalized = $step->normalized ?? [];

        $summary = match ($step->type) {
            EventType::ModelDecided => ($normalized['task'] ?? '?').': '.json_encode($normalized['output'] ?? null, JSON_UNESCAPED_UNICODE),
            EventType::Question => ($normalized['task'] ?? '?').': no valid answer ('.($normalized['reason'] ?? '?').')',
            default => json_encode(array_diff_key($normalized, array_flip(['event_ids'])), JSON_UNESCAPED_UNICODE),
        };

        return Str::limit((string) $summary, 110);
    }
}
