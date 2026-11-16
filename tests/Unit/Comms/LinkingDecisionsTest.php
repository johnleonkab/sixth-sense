<?php

use App\Comms\Linking\AgreedOperation;
use App\Comms\Linking\AgreementStatus;
use App\Comms\Linking\CriticVerdict;
use App\Comms\Linking\ExtractionAgreement;
use App\Comms\Linking\LinkCertainty;
use App\Comms\Linking\LinkDecision;
use App\Comms\Linking\LinkVote;
use App\Comms\Linking\Quote;
use App\Comms\Linking\SegmentRouter;
use App\Comms\Surface\BallGroup;
use App\Comms\Surface\BallHolder;
use Carbon\CarbonImmutable;

/*
| The decisions code makes, not models (agent-definitions 1.3, 4.3, 4.4;
| proposal 7.2). These are the contract the pipeline is built on.
*/

function intentVote(int $id, string $topic = 'workstream:spring', ?string $alternative = null): LinkVote
{
    return LinkVote::fromOutput(array_filter([
        'decision' => $alternative === null ? 'topic' : 'ambiguous',
        'topic_id' => $topic,
        'intent' => ['decision' => 'existing', 'intent_id' => (string) $id],
        'a' => $alternative === null ? null : "intent:{$id}",
        'b' => $alternative,
        'quote' => 'can we move it before Friday?',
        'why' => 'Same launch date.',
    ]));
}

test('three runs that agree place the segment for certain', function () {
    $outcome = (new LinkDecision)->decide([intentVote(481), intentVote(481), intentVote(481)], ['intent:481']);

    expect($outcome->target)->toBe('intent:481')
        ->and($outcome->intentId())->toBe(481)
        ->and($outcome->topicSubject)->toBe('workstream:spring')
        ->and($outcome->certainty)->toBe(LinkCertainty::Certain)
        ->and($outcome->agreement)->toBe('3of3')
        ->and($outcome->outcomeColumn())->toBe('topic_intent')
        ->and($outcome->quote)->toBe('can we move it before Friday?');
});

test('two of three place it on the majority, uncertain, with the other choice as the alternative', function () {
    $outcome = (new LinkDecision)->decide([intentVote(481), intentVote(495), intentVote(481)], ['intent:495', 'intent:481']);

    expect($outcome->target)->toBe('intent:481')
        ->and($outcome->certainty)->toBe(LinkCertainty::Uncertain)
        ->and($outcome->agreement)->toBe('2of3')
        ->and($outcome->alternative)->toBe('intent:495');
});

test('with no majority the existing choice ranked highest by the retriever wins', function () {
    $outcome = (new LinkDecision)->decide([intentVote(1), intentVote(2), intentVote(3)], ['intent:3', 'intent:1', 'intent:2']);

    expect($outcome->target)->toBe('intent:3')
        ->and($outcome->certainty)->toBe(LinkCertainty::Uncertain)
        ->and($outcome->agreement)->toBe('none')
        ->and($outcome->alternative)->toBe('intent:1');
});

test('an ambiguous answer is a vote for a, and b is kept as the alternative', function () {
    $outcome = (new LinkDecision)->decide([intentVote(481, alternative: 'intent:495'), intentVote(481), intentVote(481)], []);

    expect($outcome->target)->toBe('intent:481')
        ->and($outcome->certainty)->toBe(LinkCertainty::Certain)
        ->and($outcome->alternative)->toBe('intent:495');
});

test('an invalid run disagrees, so two valid matching runs are not certain', function () {
    $outcome = (new LinkDecision)->decide([intentVote(481), LinkVote::invalid(), intentVote(481)], []);

    expect($outcome->certainty)->toBe(LinkCertainty::Uncertain)->and($outcome->agreement)->toBe('2of3');
});

test('a segment is never left unplaced: no valid run, or no existing choice, becomes a new topic', function () {
    $none = (new LinkDecision)->decide([LinkVote::invalid(), LinkVote::invalid(), LinkVote::invalid()], []);
    $split = (new LinkDecision)->decide([
        LinkVote::fromOutput(['decision' => 'new_topic', 'name' => 'Pixel Meta', 'quote' => 'x', 'why' => 'y']),
        LinkVote::fromOutput(['decision' => 'none', 'quote' => 'x', 'why' => 'y']),
        LinkVote::invalid(),
    ], []);

    expect($none->target)->toBe('new_topic')->and($none->certainty)->toBe(LinkCertainty::Uncertain)
        ->and($split->target)->toBe('new_topic')->and($split->newTopicName)->toBe('Pixel Meta');
});

test('a topic with no existing intent is its own target, so a new intent opens there', function () {
    $vote = LinkVote::fromOutput(['decision' => 'topic', 'topic_id' => 'workstream:pricing', 'intent' => ['decision' => 'no_existing_intent'], 'quote' => 'x', 'why' => 'y']);
    $outcome = (new LinkDecision)->decide([$vote, $vote, $vote], []);

    expect($outcome->target)->toBe('topic:workstream:pricing')
        ->and($outcome->topicSubject)->toBe('workstream:pricing')
        ->and($outcome->outcomeColumn())->toBe('topic_new_intent');
});

function extraction(): ExtractionAgreement
{
    // Equal sentences are similar; anything else is not. Real runs use embeddings.
    return new ExtractionAgreement(fn (string $a, string $b): float => mb_strtolower($a) === mb_strtolower($b) ? 1.0 : 0.0);
}

function openOp(array $override = []): array
{
    return [...[
        'op' => 'open', 'kind' => 'request', 'what' => 'Confirm the launch date with Marta',
        'requesters' => ['person:12'], 'responders' => ['person:1'],
        'deadline' => ['date' => '2026-11-20', 'text' => 'before Friday'], 'quote' => 'can we move it?',
    ], ...$override];
}

test('an open every run returned with the same fields is agreed', function () {
    $agreed = extraction()->compare([[openOp()], [openOp()], [openOp(['responders' => ['person:1']])]]);

    expect($agreed)->toHaveCount(1)
        ->and($agreed[0]->status)->toBe(AgreementStatus::Agreed)
        ->and($agreed[0]->runs)->toBe(3);
});

test('runs that agree on the ask but not on a field open it with that field unclear', function () {
    $agreed = extraction()->compare([[openOp()], [openOp(['deadline' => ['date' => '2026-11-21', 'text' => 'Friday']])], [openOp(['responders' => ['person:1', 'person:7']])]]);

    expect($agreed[0]->status)->toBe(AgreementStatus::UnclearFields)
        ->and($agreed[0]->unclearFields)->toBe(['responders', 'deadline'])
        ->and($agreed[0]->operation['deadline'])->toBeNull()
        ->and($agreed[0]->operation['kind'])->toBe('request');
});

test('runs that disagree on whether there is an ask make it a possible ask', function () {
    $agreed = extraction()->compare([[openOp()], [['op' => 'noise', 'quote' => 'x']], [openOp()]]);

    $open = collect($agreed)->first(fn (AgreedOperation $operation): bool => $operation->op() === 'open');
    $noise = collect($agreed)->first(fn (AgreedOperation $operation): bool => $operation->op() === 'noise');

    expect($open->status)->toBe(AgreementStatus::PossibleAsk)->and($open->runs)->toBe(2)
        ->and($noise->status)->toBe(AgreementStatus::Uncertain);
});

test('a message with two asks gives two agreed opens', function () {
    $second = openOp(['what' => 'Send the Q4 report on Thursday']);
    $agreed = extraction()->compare([[openOp(), $second], [$second, openOp()], [openOp(), $second]]);

    expect($agreed)->toHaveCount(2)
        ->and(collect($agreed)->every(fn (AgreedOperation $operation): bool => $operation->status === AgreementStatus::Agreed))->toBeTrue();
});

test('other operations agree only when every run returned them on the same intent', function () {
    $settle = ['op' => 'settle', 'intent_id' => '470', 'settle_reason' => 'delivery', 'quote' => 'aquí lo tienes'];
    $agreed = extraction()->compare([[$settle], [$settle], [['op' => 'acknowledge', 'intent_id' => '470', 'quote' => 'ok']]]);

    expect(collect($agreed)->firstWhere(fn ($operation) => $operation->op() === 'settle')->status)->toBe(AgreementStatus::Uncertain);
});

test('the router applies agreed operations and keeps doubt away from playbooks', function () {
    $router = new SegmentRouter;
    $agreed = new AgreedOperation(openOp(), AgreementStatus::Agreed);
    $possible = new AgreedOperation(openOp(), AgreementStatus::PossibleAsk);

    $certain = $router->route(LinkCertainty::Certain, $agreed);
    $uncertainLink = $router->route(LinkCertainty::Uncertain, $agreed);
    $refuted = $router->route(LinkCertainty::Certain, $agreed, criticRefuted: true);
    $doubted = $router->route(LinkCertainty::Certain, $possible);
    $signal = $router->route(LinkCertainty::Certain, new AgreedOperation(['op' => 'signal', 'quote' => 'x'], AgreementStatus::Agreed));
    $noise = $router->route(LinkCertainty::Certain, new AgreedOperation(['op' => 'noise', 'quote' => 'x'], AgreementStatus::Agreed));

    expect([$certain->apply, $certain->uncertainOperation, $certain->playbooksAllowed])->toBe([true, false, true])
        ->and([$uncertainLink->apply, $uncertainLink->uncertainLink, $uncertainLink->playbooksAllowed])->toBe([true, true, true])
        ->and([$refuted->apply, $refuted->uncertainOperation, $refuted->playbooksAllowed])->toBe([true, true, false])
        ->and([$doubted->uncertainOperation, $doubted->playbooksAllowed])->toBe([true, false])
        ->and([$signal->apply, $signal->recordSignal])->toBe([false, true])
        ->and([$noise->apply, $noise->recordSignal])->toBe([false, false]);
});

test('the critic refutes only with a quote found in the evidence', function () {
    $evidence = ["Marta: no rush, just thinking out loud,\n> we should grab a coffee"];

    $valid = CriticVerdict::read(['addressed_to_user' => ['verdict' => 'holds'], 'already_done' => ['verdict' => 'cannot_tell'], 'real_ask' => ['verdict' => 'refuted', 'quote' => 'just thinking out loud, we should grab a coffee']], $evidence);
    $invented = CriticVerdict::read(['addressed_to_user' => ['verdict' => 'refuted', 'quote' => 'this is for Dani'], 'already_done' => ['verdict' => 'holds'], 'real_ask' => ['verdict' => 'holds']], $evidence);

    expect($valid->refuted)->toBeTrue()->and($valid->refutedBy)->toBe(['real_ask'])
        ->and($invented->refuted)->toBeFalse()->and($invented->ignored)->toBe(['addressed_to_user']);
});

test('a quote matches across line breaks and reply prefixes, never when empty', function () {
    expect(Quote::in("Hola,\r\n> ¿puedes revisar\r\n> el flujo?", '¿puedes revisar el flujo?'))->toBeTrue()
        ->and(Quote::in('texto', ''))->toBeFalse()
        ->and(Quote::in('texto', 'otro'))->toBeFalse();
});

test('the ball holder puts each intent in one home group', function () {
    $now = CarbonImmutable::parse('2026-11-16 10:00');
    $base = ['state' => 'open', 'settled_at' => null, 'possible_ask' => false, 'snoozed_until' => null, 'user_is_responder' => false, 'user_is_requester' => false, 'pending_proposals' => 0, 'open_questions' => 0, 'executed_actions' => 0, 'active_runs' => 0, 'waiting_watchers' => 0];
    $group = fn (array $facts): BallGroup => BallHolder::group([...$base, ...$facts], $now);

    expect($group(['user_is_responder' => true]))->toBe(BallGroup::NeedsYou)
        ->and($group(['user_is_responder' => true, 'active_runs' => 1]))->toBe(BallGroup::SystemWorking)
        ->and($group(['active_runs' => 1, 'pending_proposals' => 1]))->toBe(BallGroup::NeedsYou)
        ->and($group(['user_is_requester' => true]))->toBe(BallGroup::WaitingOnOthers)
        ->and($group(['user_is_responder' => true, 'executed_actions' => 1, 'waiting_watchers' => 1]))->toBe(BallGroup::WaitingOnOthers)
        ->and($group(['state' => 'settled', 'settled_at' => $now->subHour()]))->toBe(BallGroup::DoneToday)
        ->and($group(['state' => 'settled', 'settled_at' => $now->subDay()]))->toBe(BallGroup::Hidden)
        ->and($group(['possible_ask' => true, 'user_is_responder' => true]))->toBe(BallGroup::PossibleAsk)
        ->and($group(['user_is_responder' => true, 'snoozed_until' => $now->addDay()]))->toBe(BallGroup::Hidden)
        ->and($group(['state' => 'stale']))->toBe(BallGroup::Stale)
        ->and(BallGroup::NeedsYou->label())->toBe('Te toca');
});
