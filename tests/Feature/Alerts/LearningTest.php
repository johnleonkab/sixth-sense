<?php

use App\Actions\Actions;
use App\Agents\Evals\EvalCase;
use App\Alerts\Actions\ClassifiedAction;
use App\Alerts\AlertSource;
use App\Alerts\Lessons\InertiaReview;
use App\Alerts\Lessons\Lessons;
use App\Alerts\Lessons\LlmLessonWriter;
use App\Alerts\Outcomes\ProposalOutcomes;
use App\Alerts\Replies\AlertBrief;
use App\Alerts\Replies\LlmReplyPlanner;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Jobs\ScheduleTask;
use App\Models\AlertSetting;
use App\Models\InboxItem;
use App\Models\IntentLesson;
use App\Models\ProposalOutcome;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecordingTextModel;

beforeEach(function () {
    $this->app->instance(Chats::class, new FakeChats);
    $this->user = User::factory()->create(['name' => 'John León']);
    $this->alert = InboxItem::factory()->for($this->user)->create([
        'source' => AlertSource::Slack, 'external_id' => 'demo:C01:1001.000400', 'actor_name' => 'Marta Ruiz', 'title' => 'Prepago',
        'proposal' => ['summary' => 'Responder.', 'reply' => ['to' => 'thread', 'text' => 'Hola Marta, le echo un vistazo y te digo algo antes de las 12.']],
    ]);
});

function replyInstructions(string $from = 'Marta Ruiz <marta@cm.com>'): string
{
    $model = new RecordingTextModel(['summary' => 'Responder.', 'reply_to' => 'thread', 'reply_text' => 'Ok', 'other_actions' => [], 'question' => '']);
    (new LlmReplyPlanner($model))->plan(test()->user, new AlertBrief(AlertSource::Slack, '#producto', $from, null, '¿Lo miras?', CarbonImmutable::now()));

    return $model->calls[0]['system'];
}

test('signals weigh as the pillars say', function (string $outcome, ?string $reason, string $weight) {
    expect(ProposalOutcome::factory()->make(['outcome' => $outcome, 'reason' => $reason])->weight())->toBe($weight);
})->with([
    'edited' => [ProposalOutcome::EDITED, null, ProposalOutcome::HIGH],
    'discarded with a reason' => [ProposalOutcome::DISCARDED, 'not_for_me', ProposalOutcome::HIGH],
    'discarded without one' => [ProposalOutcome::DISCARDED, null, ProposalOutcome::LOW],
    'sent as is' => [ProposalOutcome::SENT, null, ProposalOutcome::MEDIUM],
    'expired' => [ProposalOutcome::EXPIRED, null, ProposalOutcome::MEDIUM],
    'people chosen' => [ProposalOutcome::PEOPLE_CHOSEN, null, ProposalOutcome::HIGH],
    'length changed' => [ProposalOutcome::DURATION_CHANGED, null, ProposalOutcome::HIGH],
]);

test('an edit before sending becomes a lesson the reply engine reads next time, about that person', function () {
    $this->actingAs($this->user)->postJson(route('alerts.reply', $this->alert), ['text' => 'Ok, lo miro.'])->assertOk();

    $lesson = $this->user->intentLessons()->sole();

    expect($lesson)->toMatchArray(['layer' => 'reply', 'about' => 'Marta Ruiz', 'topic' => 'draft'])
        ->and($lesson->body)->toContain('«Ok, lo miro.»')
        ->and(ProposalOutcome::sole()->learned_at)->not->toBeNull()
        ->and(replyInstructions())->toContain('Aprendido de avisos anteriores (datos):')->toContain('«Ok, lo miro.»')
        ->and($lesson->refresh()->uses)->toBe(1);
});

test('each layer reads its own lessons, those about the sender first; a newer one on the same topic replaces the old, and unused ones fade', function () {
    $lessons = app(Lessons::class);
    $lessons->learn($this->user, 'Con Pablo, tono formal.', 'reply', 'Pablo Gil', 'tone');
    $lessons->learn($this->user, 'Con Marta, tono formal.', 'reply', 'Marta Ruiz', 'tone');
    $lessons->learn($this->user, 'Con Marta, tono informal y sin saludo.', 'reply', 'Marta Ruiz', 'tone');
    $lessons->learn($this->user, 'Los avisos de facturación no importan.', 'judge');
    $old = $lessons->learn($this->user, 'Algo de hace mucho.', 'reply');
    $old->forceFill(['created_at' => now()->subDays(90)])->save();

    expect($lessons->for($this->user, 'reply', 'Marta Ruiz <marta@cm.com>')->pluck('body')->all())->toBe(['Con Marta, tono informal y sin saludo.', 'Con Pablo, tono formal.'])
        ->and($lessons->for($this->user, 'judge')->pluck('body')->all())->toBe(['Los avisos de facturación no importan.']);
});

test('a discard with its reason teaches only its layer', function () {
    app(ProposalOutcomes::class)->discarded($this->alert, 'not_important');

    expect($this->user->intentLessons()->sole()->layer)->toBe('judge')
        ->and(replyInstructions())->not->toContain('Aprendido de avisos anteriores');
});

test('saying no to an action teaches the classifier and joins its evaluation set; yes to a doubt too', function () {
    $this->alert->update(['body' => '¿Lo vemos en una llamada?', 'proposal' => ['summary' => 'Responder.', 'actions' => [
        (new ClassifiedAction(ClassifiedAction::TASK, ClassifiedAction::DOUBT, 'Revisar el flujo', '¿Es una tarea?'))->toArray('a0'),
        (new ClassifiedAction(ClassifiedAction::CALL, ClassifiedAction::DOUBT, 'Llamada con Marta', '¿Te incluye?', ['people' => ['Marta'], 'people_sure' => true, 'duration_minutes' => 30]))->toArray('a1'),
    ]]]);
    $settle = fn (string $key, string $answer) => $this->actingAs($this->user)->postJson(route('voice.tools.run', 'settle_alert_action'), ['arguments' => ['alert_id' => $this->alert->id, 'action' => $key, 'answer' => $answer]])->assertOk();

    $settle('a0', 'no');
    $settle('a1', 'yes');

    expect($this->user->intentLessons()->sole())->toMatchArray(['layer' => 'classifier', 'topic' => 'not-task'])
        ->and(array_map(fn (EvalCase $case): array => [$case->ask, $case->classes, $case->confidence], array_slice(EvalCase::withLearned('clasificador'), 25)))->toBe([
            ['¿Lo vemos en una llamada?', ['call'], null],
            ['¿Lo vemos en una llamada?', ['call'], 'sure'],
        ]);
});

test('changing a length teaches the scheduler', function () {
    Queue::fake([ScheduleTask::class]);
    AlertSetting::factory()->for($this->user)->create(['source' => AlertSource::Agenda, 'enabled' => true]);
    $this->alert->update(['proposal' => ['summary' => 'x', 'actions' => [(new ClassifiedAction(ClassifiedAction::CALL, ClassifiedAction::SURE, 'Llamada', data: ['people' => ['Marta'], 'people_sure' => true, 'duration_minutes' => 30]))->toArray('a0')]]]);

    $this->actingAs($this->user)->postJson(route('voice.tools.run', 'settle_alert_action'), ['arguments' => ['alert_id' => $this->alert->id, 'action' => 'a0', 'duration_minutes' => 45]])->assertOk();

    expect(ProposalOutcome::sole())->toMatchArray(['outcome' => ProposalOutcome::DURATION_CHANGED, 'detail' => ['minutes' => 45, 'was' => 30]])
        ->and($this->user->intentLessons()->sole())->toMatchArray(['layer' => 'scheduler', 'about' => 'Marta Ruiz', 'body' => 'Con Marta Ruiz, las llamadas duran 45 minutos.']);
});

test('the lesson writer keeps only usable lessons, at most two', function () {
    $outcome = ProposalOutcome::factory()->for($this->user)->create(['inbox_item_id' => $this->alert->id, 'outcome' => ProposalOutcome::EDITED, 'detail' => ['proposed' => 'Hola Marta, …', 'sent' => 'Ok']]);
    $model = new RecordingTextModel(['lessons' => [
        ['layer' => 'reply', 'about' => 'Marta Ruiz', 'topic' => 'Tone', 'body' => 'Con Marta, respuestas de una línea.'],
        ['layer' => 'nonsense', 'about' => '', 'topic' => 'x', 'body' => 'Ignorada.'],
        ['layer' => 'reply', 'about' => '', 'topic' => 'length', 'body' => ''],
    ]]);

    expect((new LlmLessonWriter($model))->write($outcome))->toBe([['layer' => 'reply', 'about' => 'Marta Ruiz', 'topic' => 'tone', 'body' => 'Con Marta, respuestas de una línea.']])
        ->and($model->calls[0]['user'])->toContain("Propuesto: Hola Marta, …\nEnviado: Ok");
});

test('when nearly everything goes out untouched, a few of the week\'s sends are shown again', function (int $sent, int $edited, int $sample) {
    foreach (range(1, $sent) as $ignored) {
        ProposalOutcome::factory()->for($this->user)->create(['inbox_item_id' => $this->alert->id, 'pending_action_id' => app(Actions::class)->propose($this->user, 'chat.send', 'x', ['message' => ['text' => 'Hecho.']])->id]);
    }

    ProposalOutcome::factory()->count($edited)->for($this->user)->create(['outcome' => ProposalOutcome::EDITED, 'learned_at' => now()]);
    $review = app(InertiaReview::class)->for($this->user);

    expect($review['sample'])->toHaveCount($sample)
        ->and($review['sample'][0]['text'] ?? 'Hecho.')->toBe('Hecho.');
})->with([
    'almost all as is' => [10, 0, 5],
    'often edited' => [8, 2, 0],
]);

test('lessons can be read, corrected and deleted in Settings, only one\'s own', function () {
    $lesson = app(Lessons::class)->learn($this->user, 'Con Marta, tono informal.', 'reply', 'Marta Ruiz', 'tone');
    $other = IntentLesson::query()->create(['user_id' => User::factory()->create()->id, 'body' => 'Ajena.']);

    $this->actingAs($this->user)->get(route('settings.lessons.index'))
        ->assertInertia(fn ($page) => $page->component('settings/lessons')->where('lessons.0.body', 'Con Marta, tono informal.')->where('lessons.0.layer_label', 'Respuestas')->has('inertia.sample', 0));

    $this->actingAs($this->user)->put(route('settings.lessons.update', $lesson->id), ['body' => 'Con Marta, tono informal y breve.'])->assertRedirect();
    $this->actingAs($this->user)->delete(route('settings.lessons.destroy', $other->id))->assertNotFound();
    $this->actingAs($this->user)->delete(route('settings.lessons.destroy', $lesson->id))->assertRedirect();

    expect(IntentLesson::query()->pluck('body')->all())->toBe(['Ajena.']);
});
