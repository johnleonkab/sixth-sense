<?php

use App\Alerts\Actions\ActionClassifier;
use App\Alerts\Actions\ClassifiedAction;
use App\Alerts\Actions\FakeActionClassifier;
use App\Alerts\AlertFollowUp;
use App\Alerts\AlertSource;
use App\Alerts\Recipients\RecipientScope;
use App\Alerts\Replies\FakeReplyPlanner;
use App\Alerts\Replies\ReplyPlanner;
use App\Connectors\Chat\ChatPerson;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\FakeChats;
use App\Knowledge\KnowledgeSource;
use App\Models\ConnectedAccount;
use App\Models\InboxItem;
use App\Models\KnowledgeDocument;
use App\Models\User;

beforeEach(function () {
    $this->app->instance(Chats::class, $this->chats = new class extends FakeChats
    {
        /** Someone from a partner company in a shared channel, and someone from another team. */
        public function people(User $user, string $query, int $limit = 10): array
        {
            return match ($query) {
                'Iván Ortega' => [new ChatPerson('demo:U09', 'demo@sixth-sense.test', 'Iván Ortega', 'ivan', null, 'ivan@partner.test')],
                'Sara Vidal' => [new ChatPerson('demo:U08', 'demo@sixth-sense.test', 'Sara Vidal', 'sara', null, 'sara@covermanager.test')],
                default => parent::people($user, $query, $limit),
            };
        }
    });
    $this->app->instance(ReplyPlanner::class, new FakeReplyPlanner);
    $this->app->instance(ActionClassifier::class, $this->classifier = new FakeActionClassifier);
    $this->user = User::factory()->create(['name' => 'John León']);
    ConnectedAccount::factory()->for($this->user)->create(['provider' => 'google', 'email' => 'john@covermanager.test']);
});

function slackAlertFrom(string $name): InboxItem
{
    return InboxItem::factory()->for(test()->user)->create(['source' => AlertSource::Slack, 'external_id' => 'demo:C01:1001.000400', 'actor_name' => $name]);
}

test('internal data is issue keys and links to private tools', function () {
    expect(app(RecipientScope::class)->internalData('Va en CPD-405, mira https://cm.atlassian.net/browse/CPD-405 y https://notion.so/Plan-123 o https://example.com'))
        ->toBe(['CPD-405', 'https://cm.atlassian.net/browse/CPD-405', 'https://notion.so/Plan-123']);
});

test('whom a reply goes to: inside the organisation, another team, or outside it', function (string $source, string $name, ?string $email, string $scope) {
    $item = $source === 'slack'
        ? slackAlertFrom($name)
        : InboxItem::factory()->for($this->user)->create(['source' => AlertSource::Gmail, 'actor_name' => $name]);

    expect(app(RecipientScope::class)->of($item, $email)['scope'])->toBe($scope);
})->with([
    'a usual colleague in Slack' => ['slack', 'Marta Ruiz', null, RecipientScope::INTERNAL],
    'someone from another team in Slack' => ['slack', 'Sara Vidal', null, RecipientScope::OTHER_TEAM],
    'a guest from another company in Slack' => ['slack', 'Iván Ortega', null, RecipientScope::EXTERNAL],
    'an email from the same domain' => ['gmail', 'Marta Ruiz', 'marta@covermanager.test', RecipientScope::INTERNAL],
    'an email from another domain' => ['gmail', 'Cliente', 'compras@grupovela.test', RecipientScope::EXTERNAL],
]);

test('each proposal keeps its sources, and marks whom it goes to when outside the circle', function () {
    KnowledgeDocument::factory()->for($this->user)->text('revisarlo antes de la 1:1 con Marta: puntos de la revisión')->create(['source' => KnowledgeSource::Notion, 'title' => 'Notas de la 1:1 con Marta', 'url' => 'https://notion.so/one-on-one']);
    $this->classifier->answer = [new ClassifiedAction(ClassifiedAction::REPLY, ClassifiedAction::SURE, 'Responder.')];
    $alert = InboxItem::factory()->for($this->user)->create(['source' => AlertSource::Slack, 'external_id' => 'demo:C01:1001.000400', 'actor_name' => 'Iván Ortega']);

    app(AlertFollowUp::class)->plan($alert);

    expect($alert->refresh()->proposal['recipient'])->toMatchArray(['scope' => RecipientScope::EXTERNAL, 'label' => 'Iván Ortega es de fuera de tu organización', 'internal' => []])
        ->and(collect($alert->proposal['sources'])->pluck('title'))->toContain('Notas de la 1:1 con Marta');

    $alert->update(['actor_name' => 'Marta Ruiz']);
    app(AlertFollowUp::class)->plan($alert);

    expect($alert->refresh()->proposal['recipient'])->toBeNull();
});

test('internal data to someone outside asks for one more yes; inside the circle it goes at once', function () {
    $alert = slackAlertFrom('Iván Ortega');
    $alert->update(['proposal' => ['summary' => 'Responder.', 'reply' => ['to' => 'thread', 'text' => 'Va en CPD-405.'], 'recipient' => ['scope' => 'external', 'label' => 'Iván Ortega es de fuera de tu organización', 'internal' => ['CPD-405']]]]);

    $this->actingAs($this->user)->postJson(route('alerts.reply', $alert))
        ->assertStatus(409)
        ->assertJsonPath('needs_confirmation', true)
        ->assertJsonPath('message', 'Iván Ortega es de fuera de tu organización y la respuesta lleva datos internos (CPD-405). ¿La envías igualmente?');

    expect($this->chats->sent)->toBe([]);

    // Edited so nothing internal is left, it goes without asking.
    $this->actingAs($this->user)->postJson(route('alerts.reply', $alert), ['text' => 'Va en marcha, te cuento el jueves.'])->assertOk();
    $this->actingAs($this->user)->postJson(route('alerts.reply', $alert), ['confirm_internal' => true])->assertOk();

    expect($this->chats->sent)->toHaveCount(2);
});
