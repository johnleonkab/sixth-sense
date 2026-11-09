<?php

namespace App\Comms\Playbooks;

use App\Comms\Calendar\SlotFinder;
use App\Comms\Gate\ActionClass;
use App\Comms\Gate\Gate;
use App\Comms\Models\ModelAnswerKind;
use App\Comms\Models\ModelRouter;
use App\Comms\Playbooks\Steps\Ask;
use App\Comms\Playbooks\Steps\Propose;
use App\Comms\Watchers\BusinessCalendar;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPlaybookRun;
use App\Models\Comms\CommsProposal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;

/**
 * Cases 3, 8, 12, 46 and 61. Time is proposed. The run waits until the
 * page is edited, then asks whether to send. No model call on the way back.
 */
class PrepareWork
{
    public function __construct(
        private SlotFinder $slots,
        private BusinessCalendar $calendar,
        private Gate $gate,
        private Propose $propose,
        private Ask $ask,
        private Container $container,
    ) {}

    public function run(User $user, CommsIntent $intent, CommsMessage $message): void
    {
        $run = CommsPlaybookRun::query()->updateOrCreate(
            ['user_id' => $user->id, 'key' => 'run:'.$intent->key],
            [
                'playbook' => 'prepare_work',
                'intent_id' => $intent->id,
                'state' => 'running',
                'step' => 'draft',
                'context' => [],
                'trigger_event_id' => $message->event_id,
            ],
        );

        $answer = $this->container->make(ModelRouter::class)->ask(
            user: $user,
            task: 'draft',
            input: $message->text,
            schema: [
                'type' => 'object',
                'required' => ['band', 'reason', 'underspecified'],
                'properties' => [
                    'band' => ['type' => 'integer'],
                    'reason' => ['type' => 'string'],
                    'underspecified' => ['type' => 'boolean'],
                ],
            ],
            eventIds: [$message->event_id],
        );

        if ($answer->kind !== ModelAnswerKind::Accepted || ! is_array($answer->output)) {
            return;
        }

        if (($answer->output['underspecified'] ?? false) === true) {
            $this->ask->open($user, $intent, 'Falta concretar el encargo.', ['draft' => ''], [$message->event_id]);
            $run->state = 'waiting';
            $run->step = 'clarify';
            $run->save();

            return;
        }

        $deadline = $intent->deadline !== null
            ? CarbonImmutable::parse($intent->deadline)->endOfDay()
            : $this->calendar->addDays($user, CarbonImmutable::now(), 2);
        $slots = $this->slots->find(
            $user,
            CarbonImmutable::now(),
            $deadline,
            (int) $answer->output['band'],
            (bool) ($intent->flags['urgent'] ?? false),
        );

        if ($slots === []) {
            $this->attach($run, $this->propose->submit(
                $user,
                $intent,
                $message,
                'No cabe antes del plazo. ¿Pido más tiempo?',
                [],
                facts: false,
            ));
            $run->state = 'done';
            $run->step = 'more_time';
            $run->save();

            return;
        }

        $pageRef = 'notion:'.$intent->key;
        $target = [
            'ref' => 'self',
            'channel' => (string) $message->channel_ref,
            'thread' => $message->thread_ref,
        ];
        $this->attach($run, $this->gate->submit(
            user: $user,
            class: ActionClass::NotionCreatePage,
            target: $target,
            payload: [
                'text' => (string) $answer->output['reason'],
                'title' => $intent->what,
                'ref' => $pageRef,
            ],
            intent: $intent,
            sourceEventIds: [$message->event_id],
        ));
        $this->attach($run, $this->gate->submit(
            user: $user,
            class: ActionClass::CalendarCreate,
            target: $target,
            payload: [
                'text' => 'Bloque: '.$intent->what,
                'title' => 'Bloque: '.$intent->what,
                'starts_at' => $slots[0]['starts_at'],
                'ends_at' => $slots[0]['ends_at'],
            ],
            intent: $intent,
            sourceEventIds: [$message->event_id],
        ));
        $this->attach($run, $this->propose->submit(
            $user,
            $intent,
            $message,
            'Lo miro y te escribo cuando esté.',
            [],
            facts: false,
        ));

        $run->state = 'awaiting_approval';
        $run->step = 'propose';
        $run->context = ['resume_step' => 'page', 'page_ref' => $pageRef];
        $run->save();
    }

    private function attach(CommsPlaybookRun $run, CommsProposal $proposal): void
    {
        $proposal->run_id = $run->id;
        $proposal->save();
    }
}
