<?php

use App\Alerts\AlertSource;
use App\Alerts\InboxCandidate;
use App\Alerts\Judges\LlmRelevanceJudge;
use App\Alerts\Outcomes\ProposalOutcomes;
use App\Alerts\Outcomes\ResolutionInference;
use App\Alerts\Replies\AlertBrief;
use App\Alerts\Replies\LlmReplyPlanner;
use App\Connectors\Calendar\CalendarReader;
use App\Connectors\Calendar\FakeCalendarReader;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Knowledge\Gaps\GapKind;
use App\Knowledge\Gaps\GapQuestions;
use App\Models\InboxItem;
use App\Models\ProposalOutcome;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Support\RecordingTextModel;

beforeEach(function () {
    // Wednesday 14 October, 18:00 in Madrid: the sample day's meetings already happened.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 18:00', 'Europe/Madrid'));
    $this->app->instance(Chats::class, $this->chats = new FakeChats);
    $this->app->instance(CalendarReader::class, new FakeCalendarReader);
    $this->user = User::factory()->create(['name' => 'John León']);
    $this->alert = InboxItem::factory()->for($this->user)->create([
        'source' => AlertSource::Slack,
        'external_id' => 'demo:C01:1001.000400',
        'actor_name' => 'Andrés Vela',
        'title' => 'Contrato',
        'received_at' => CarbonImmutable::parse('2026-10-14 08:00', 'Europe/Madrid'),
        'proposal' => ['summary' => 'Responder a Andrés que lo firmas mañana.', 'reply' => ['to' => 'thread', 'text' => 'Lo firmo mañana.']],
    ]);
});

function resolveAlert(array $arguments): void
{
    test()->actingAs(test()->user)->postJson(route('voice.tools.run', 'resolve_alert'), ['arguments' => ['alert_id' => test()->alert->id, ...$arguments]])->assertOk();
}

test('each discard reason becomes a lesson, and without a reason nothing is learned', function (string $reason, string $lesson) {
    resolveAlert(['outcome' => 'dismissed', 'reason' => $reason]);

    expect($this->user->intentLessons()->sole()->body)->toBe($lesson);
})->with([
    'not important' => ['not_important', 'No le importó: Slack de Andrés Vela sobre «Contrato». No avisar de mensajes así.'],
    'not for me' => ['not_for_me', 'No era para él: Slack de Andrés Vela sobre «Contrato». Le tocaba a otra persona.'],
    'bad proposal' => ['bad_proposal', 'Importaba, pero la propuesta no servía: Slack de Andrés Vela sobre «Contrato». Se propuso: «Responder a Andrés que lo firmas mañana.».'],
]);

test('a discard without a reason teaches nothing', function () {
    resolveAlert(['outcome' => 'dismissed']);

    expect($this->user->intentLessons()->count())->toBe(0)
        ->and($this->alert->outcomes()->sole()->reason)->toBeNull();
});

test('the relevance judge and the reply engine read what was learned', function () {
    $this->user->intentLessons()->create(['body' => 'No le importó: Slack de Andrés Vela sobre «Contrato».']);

    $judge = new RecordingTextModel(['important' => false, 'reason' => 'x', 'relevance' => 0]);
    (new LlmRelevanceJudge($judge))->judge($this->user, InboxCandidate::fromArray(['source' => 'slack', 'external_id' => 'x', 'account' => '1', 'from' => ['name' => 'Andrés'], 'subject' => 'Hola', 'excerpt' => 'Hola', 'received_at' => now()->toIso8601String()]));

    $planner = new RecordingTextModel(['summary' => 'Nada.', 'reply_to' => 'none', 'reply_text' => '', 'other_actions' => [], 'question' => '']);
    (new LlmReplyPlanner($planner))->plan($this->user, new AlertBrief(source: AlertSource::Slack, where: '#producto', from: 'Andrés', subject: null, text: 'Hola', receivedAt: CarbonImmutable::now()));

    expect($judge->calls[0]['system'])->toContain('No le importó: Slack de Andrés Vela')
        ->and($planner->calls[0]['system'])->toContain('No le importó: Slack de Andrés Vela');
});

test('marking it resolved works out how: the person answered, or met whoever asked', function () {
    $this->chats->answered = ['demo:C01:1001.000400'];
    resolveAlert(['outcome' => 'done']);

    expect($this->alert->outcomes()->sole()->detail['how'])->toBe('replied');

    $marta = InboxItem::factory()->for($this->user)->create(['source' => AlertSource::Gmail, 'external_id' => 'demo.99', 'actor_name' => 'Marta Ruiz', 'received_at' => CarbonImmutable::parse('2026-10-14 08:00', 'Europe/Madrid')]);
    $this->actingAs($this->user)->postJson(route('voice.tools.run', 'resolve_alert'), ['arguments' => ['alert_id' => $marta->id, 'outcome' => 'done']]);

    expect($marta->outcomes()->sole()->detail['how'])->toBe('met');
});

test('when nothing says how, it asks — at most once a week — and the answer is kept with the outcome', function () {
    // Nothing to read in a service, and no meeting with whoever asked.
    $this->alert->update(['actor_name' => 'Nadie Conocido', 'source' => AlertSource::Agenda]);
    resolveAlert(['outcome' => 'done']);

    $gap = $this->user->knowledgeGaps()->sole();

    expect($gap->kind)->toBe(GapKind::Resolution)
        ->and($gap->question)->toContain('Diste por resuelto el aviso de Nadie Conocido');

    $other = InboxItem::factory()->for($this->user)->create(['actor_name' => 'Otro Desconocido', 'source' => AlertSource::Calendar]);
    $this->actingAs($this->user)->postJson(route('voice.tools.run', 'resolve_alert'), ['arguments' => ['alert_id' => $other->id, 'outcome' => 'done']]);

    expect($this->user->knowledgeGaps()->count())->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Europe/Madrid'));
    expect(app(GapQuestions::class)->ask($this->user))->toBe(1);

    $question = $this->user->inboxItems()->where('source', AlertSource::Questions)->sole();
    expect($question->title)->toBe('¿Cómo se resolvió «Nadie Conocido: Contrato»?');

    app(GapQuestions::class)->answer($gap->refresh(), 'Lo hablamos en persona.');

    expect($this->alert->outcomes()->sole()->detail)->toMatchArray(['how' => 'told', 'told' => 'Lo hablamos en persona.']);
});

test('an outcome of an alert that no longer exists is left alone', function () {
    $outcome = ProposalOutcome::factory()->create();

    expect(app(ResolutionInference::class)->infer($outcome))->toBeNull()
        ->and(app(ProposalOutcomes::class)->discarded($this->alert, 'otra')->reason)->toBeNull();
});
