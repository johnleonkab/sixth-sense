<?php

use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Alerts\InboxCandidate;
use App\Alerts\Intents\IntentAssigner;
use App\Alerts\Replies\FakeReplyPlanner;
use App\Alerts\Replies\ReplyPlanner;
use App\Connectors\Mail\MailAddress;
use App\Models\IntentLesson;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'John León']);
    $this->app->instance(ReplyPlanner::class, new FakeReplyPlanner);
});

function ask(string $id, AlertSource $source, string $text, ?string $thread = null): InboxCandidate
{
    return new InboxCandidate(
        source: $source,
        externalId: $id,
        account: 'jhon@work.com',
        from: new MailAddress('Andrés Vela', 'andres@grupovela.com'),
        to: [],
        cc: [],
        subject: $source === AlertSource::Gmail ? 'Contrato Grupo Vela' : 'Mensaje directo',
        excerpt: $text,
        receivedAt: CarbonImmutable::parse('2026-10-26 10:00', 'Europe/Madrid'),
        threadKey: $thread,
    );
}

test('the same ask from another channel joins the one alert', function () {
    $assigner = app(IntentAssigner::class);

    $email = $assigner->assign($this->user, ask('mail-1', AlertSource::Gmail, 'Necesito la firma del contrato antes del viernes', 'thread-mail'));
    $slack = $assigner->assign($this->user, ask('slack-1', AlertSource::Slack, 'Andrés, necesito la firma del contrato', 'thread-slack'));

    expect($slack->is($email))->toBeTrue()
        ->and($this->user->inboxItems()->count())->toBe(1)
        ->and($email->refresh()->source)->toBe(AlertSource::Slack)
        ->and(array_column($email->messages(), 'text'))->toContain('Necesito la firma del contrato antes del viernes')
        ->and(IntentLesson::query()->whereBelongsTo($this->user)->count())->toBeGreaterThan(0);
});

test('a resolved intent stays closed when the same conversation continues, and reopens from another channel when it matters', function () {
    $assigner = app(IntentAssigner::class);
    $intent = $assigner->assign($this->user, ask('mail-1', AlertSource::Gmail, 'Necesito la firma del contrato antes del viernes', 'thread-mail'));
    $intent->refresh()->close(AlertStatus::Resolved, 'replied');

    $same = $assigner->assign($this->user, ask('mail-2', AlertSource::Gmail, 'Sigo con el contrato, gracias', 'thread-mail'));

    expect($same)->toBeNull()
        ->and($intent->refresh()->status)->toBe(AlertStatus::Resolved);

    $again = $assigner->assign($this->user, ask('slack-9', AlertSource::Slack, 'Necesito la firma del contrato hoy, es urgente', 'thread-slack'));

    expect($again->is($intent))->toBeTrue()
        ->and($intent->refresh()->status)->toBe(AlertStatus::Pending)
        ->and($intent->proposal['summary'])->toContain('Andrés');
});

test('a message that asks for nothing and matches no intent is not listed', function () {
    $item = app(IntentAssigner::class)->assign($this->user, ask('mail-1', AlertSource::Gmail, 'Un placer verte ayer.', 'thread-mail'));

    expect($item)->toBeNull()
        ->and($this->user->inboxItems()->count())->toBe(0);
});
