<?php

namespace App\Comms\Linking;

/**
 * Step 8 of the live pipeline (agent-definitions 4.4), for one operation of
 * one segment. Named apart from the playbook router in `App\Comms\Playbooks`.
 *
 * | Link      | Extractor + critic       | Result                                         |
 * |-----------|--------------------------|------------------------------------------------|
 * | certain   | agree, critic passes     | apply; playbooks may run                       |
 * | any       | disagree, or refuted     | apply as uncertain; no playbook                |
 * | uncertain | agree, critic passes     | apply; link uncertain; playbooks may run       |
 * | any       | signal / noise           | signal recorded, or nothing                    |
 *
 * A proposal built on an uncertain link is still always asked: that rule
 * lives in the gate, not here.
 */
final class SegmentRouter
{
    /**
     * @param  bool  $criticRefuted  The critic refuted it with a quote that exists. Ignored for operations the critic does not check.
     */
    public function route(LinkCertainty $link, AgreedOperation $operation, bool $criticRefuted = false): RouteDecision
    {
        $uncertainLink = $link === LinkCertainty::Uncertain;

        if (in_array($operation->op(), ['signal', 'noise'], true)) {
            return new RouteDecision(
                apply: false,
                uncertainOperation: false,
                uncertainLink: $uncertainLink,
                playbooksAllowed: false,
                recordSignal: $operation->op() === 'signal' || $operation->status !== AgreementStatus::Agreed,
            );
        }

        $doubted = in_array($operation->status, [AgreementStatus::PossibleAsk, AgreementStatus::Uncertain], true)
            || ($criticRefuted && self::criticChecks($operation->op()));

        return new RouteDecision(
            apply: true,
            uncertainOperation: $doubted,
            uncertainLink: $uncertainLink,
            playbooksAllowed: ! $doubted,
            recordSignal: false,
        );
    }

    /**
     * The critic runs on these operations only (D7).
     */
    public static function criticChecks(string $op): bool
    {
        return in_array($op, ['open', 'settle', 'reopen'], true);
    }
}
