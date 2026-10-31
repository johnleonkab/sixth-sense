<?php

use App\Alerts\Actions\ActionClassifier;
use App\Alerts\Actions\ClassifiedAction;
use App\Alerts\Actions\FakeActionClassifier;
use App\Alerts\AlertFollowUp;
use App\Alerts\AlertSource;
use App\Alerts\Questions\QuestionAnswer;
use App\Alerts\Replies\FakeReplyPlanner;
use App\Alerts\Replies\ReplyPlanner;
use App\Connectors\Issues\FakeIssueTracker;
use App\Connectors\Issues\IssueTracker;
use App\Connectors\Wiki\FakeWiki;
use App\Connectors\Wiki\Wiki;
use App\Knowledge\KnowledgeSource;
use App\Models\InboxItem;
use App\Models\KnowledgeDocument;
use App\Models\User;

beforeEach(function () {
    $this->travelTo('2026-10-14 08:00');
    $this->app->instance(IssueTracker::class, new FakeIssueTracker);
    $this->app->instance(Wiki::class, new FakeWiki);
    $this->app->instance(ReplyPlanner::class, $this->planner = new FakeReplyPlanner);
    $this->app->instance(ActionClassifier::class, $this->classifier = new FakeActionClassifier);
    $this->user = User::factory()->create(['name' => 'John León']);
    $this->alert = InboxItem::factory()->for($this->user)->create(['source' => AlertSource::Gmail, 'external_id' => 'demo.1', 'actor_name' => 'Andrés Gil']);
});

function asks(string $about, array $terms): InboxItem
{
    test()->classifier->answer = [new ClassifiedAction(ClassifiedAction::QUESTION, ClassifiedAction::SURE, 'Andrés pregunta algo.', data: ['about' => $about, 'terms' => $terms])];
    app(AlertFollowUp::class)->plan(test()->alert);

    return test()->alert->refresh();
}

function indexed(KnowledgeSource $source, string $ref, string $title, array $extra = []): KnowledgeDocument
{
    return KnowledgeDocument::factory()->for(test()->user)->text($title)->create(['source' => $source, 'ref' => $ref, 'title' => $title, ...$extra]);
}

test('the state of a feature: one candidate in the Jira index, then the issue itself, cited with its status and date', function () {
    indexed(KnowledgeSource::Jira, 'demo:CPD-405', 'CPD-405 Integrar la pasarela de pago');
    indexed(KnowledgeSource::Jira, 'demo:CPD-400', 'CPD-400 Pagar la reserva por adelantado');

    $alert = asks('feature', ['pasarela', 'pago']);
    $findings = collect($this->planner->planned)->last()->findings;

    expect($alert->proposal['research']['outcome'])->toBe(QuestionAnswer::FOUND)
        ->and($alert->proposal['research']['sources'][0])->toMatchArray(['key' => 'CPD-405', 'status' => 'In Progress', 'url' => 'https://covermanager.atlassian.net/browse/CPD-405'])
        ->and($alert->proposal['research']['stale'])->toBeNull()
        ->and($alert->proposal['reply'])->not->toBeNull()
        ->and($findings)->toContain('Respuesta encontrada en Jira: CPD-405 Integrar la pasarela de pago, estado «In Progress», actualizado el 14/10/2026.')
        ->and($findings)->toContain('Lo lleva Pablo Gil.');
});

test('several plausible candidates are listed, the draft waits, and the one picked is drafted', function () {
    indexed(KnowledgeSource::Jira, 'demo:CPD-405', 'CPD-405 Integrar la pasarela de pago');
    indexed(KnowledgeSource::Jira, 'demo:CPD-400', 'CPD-400 Pagar la reserva por adelantado');

    $alert = asks('feature', ['pago', 'pagar', 'reserva', 'pasarela']);

    expect($alert->proposal['research']['outcome'])->toBe(QuestionAnswer::SEVERAL)
        ->and(array_column($alert->proposal['research']['sources'], 'title'))->toEqualCanonicalizing(['CPD-405 Integrar la pasarela de pago', 'CPD-400 Pagar la reserva por adelantado'])
        ->and($alert->proposal['reply'])->toBeNull()
        ->and($alert->proposal['question'])->toBe('¿Cuál de estos responde a lo que pregunta?');

    $choice = array_search('CPD-400 Pagar la reserva por adelantado', array_column($alert->proposal['research']['sources'], 'title'), true);

    $this->actingAs($this->user)->postJson(route('voice.tools.run', 'settle_alert_action'), ['arguments' => ['alert_id' => $alert->id, 'action' => 'a0', 'choice' => $choice]])
        ->assertOk()->assertJsonPath('output.say', 'Vale, te preparo la respuesta con ese.');

    expect($alert->refresh()->proposal['research']['outcome'])->toBe(QuestionAnswer::FOUND)
        ->and($alert->proposal['research']['sources'][0]['key'])->toBe('CPD-400')
        ->and($alert->proposal['reply'])->not->toBeNull()
        ->and(collect($this->planner->planned)->last()->findings)->toContain('CPD-400 Pagar la reserva por adelantado, estado «In Progress»');
});

test('nothing found is said, and the draft is not asked to invent it', function () {
    $alert = asks('feature', ['blockchain', 'loyalty']);

    expect($alert->proposal['research']['outcome'])->toBe(QuestionAnswer::NONE)
        ->and($alert->proposal['question'])->toBe('No he encontrado la respuesta: dime qué le contesto.')
        ->and(collect($this->planner->planned)->last()->findings)->toContain('No la inventes');
});

test('documentation comes from the archive and Notion, and a source untouched for two weeks is marked', function (KnowledgeSource $source, int $daysOld, ?string $stale) {
    indexed($source, 'docs/webhook-pagos.md', 'Webhook de pagos', ['url' => 'https://docs.test/webhook', 'source_updated_at' => now()->subDays($daysOld), 'summary' => 'El webhook firma cada evento con HMAC.']);

    $alert = asks('docs', ['webhook', 'pagos']);

    expect($alert->proposal['research']['outcome'])->toBe(QuestionAnswer::FOUND)
        ->and($alert->proposal['research']['sources'][0]['title'])->toBe('Webhook de pagos')
        ->and($alert->proposal['research']['stale'])->toBe($stale)
        ->and(collect($this->planner->planned)->last()->findings)->toContain('Dice: El webhook firma cada evento con HMAC.');
})->with([
    'archive, fresh' => [KnowledgeSource::Archive, 3, null],
    'notion, old' => [KnowledgeSource::Notion, 20, 'La fuente no se toca desde el 24/09: puede que no esté al día.'],
]);

test('a reply with no question asked looks nothing up', function () {
    $this->classifier->answer = [new ClassifiedAction(ClassifiedAction::REPLY, ClassifiedAction::SURE, 'Responder.')];

    app(AlertFollowUp::class)->plan($this->alert);

    expect($this->alert->refresh()->proposal)->not->toHaveKey('research');
});
