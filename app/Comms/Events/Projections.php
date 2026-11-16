<?php

namespace App\Comms\Events;

use App\Models\Comms\CommsCardView;
use App\Models\Comms\CommsContextFact;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsGoldenLabel;
use App\Models\Comms\CommsIdentity;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsIntentParticipant;
use App\Models\Comms\CommsLinkDecision;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsNotification;
use App\Models\Comms\CommsOwnedArea;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsPreference;
use App\Models\Comms\CommsQuestion;
use App\Models\Comms\CommsReconcileChange;
use App\Models\Comms\CommsSegment;
use App\Models\Comms\CommsSignal;
use App\Models\Comms\CommsTopicAlias;
use App\Models\Comms\CommsTopicSearch;
use App\Models\Comms\CommsUrgentRule;
use App\Models\Comms\CommsWatcher;
use App\Models\Comms\CommsWindow;
use App\Models\User;

/**
 * The tables replay fills. Empty groups are left out so a phase with no rows
 * still matches a fixture that expects nothing.
 */
class Projections
{
    public function clear(User $user): void
    {
        CommsLinkDecision::query()->whereBelongsTo($user)->delete();
        CommsGoldenLabel::query()->whereBelongsTo($user)->delete();
        CommsCardView::query()->whereBelongsTo($user)->delete();
        CommsContextFact::query()->whereBelongsTo($user)->delete();
        CommsNotification::query()->whereBelongsTo($user)->delete();
        CommsQuestion::query()->whereBelongsTo($user)->delete();
        CommsWatcher::query()->whereBelongsTo($user)->delete();
        CommsSignal::query()->whereBelongsTo($user)->delete();
        CommsConversationLink::query()->whereBelongsTo($user)->delete();
        CommsSegment::query()->whereBelongsTo($user)->delete();
        CommsWindow::query()->whereBelongsTo($user)->delete();
        CommsTopicAlias::query()->whereBelongsTo($user)->delete();
        CommsTopicSearch::query()->whereBelongsTo($user)->delete();
        CommsUrgentRule::query()->whereBelongsTo($user)->delete();
        CommsReconcileChange::query()->whereBelongsTo($user)->delete();
        CommsIntentParticipant::query()->whereBelongsTo($user)->delete();
        CommsIntent::query()->whereBelongsTo($user)->delete();
        CommsOwnedArea::query()->whereBelongsTo($user)->delete();
        CommsPreference::query()->whereBelongsTo($user)->delete();
        CommsMessage::query()->whereBelongsTo($user)->delete();
        CommsIdentity::query()->whereBelongsTo($user)->delete();
        CommsPerson::query()->whereBelongsTo($user)->delete();
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function capture(User $user): array
    {
        $captured = [
            'messages' => CommsMessage::query()->whereBelongsTo($user)->orderBy('source')->orderBy('external_id')->get()
                ->map(fn (CommsMessage $message): array => [
                    'source' => $message->source->value,
                    'external_id' => $message->external_id,
                    'text' => $message->text,
                    'direction' => $message->direction,
                    'deleted' => $message->deleted_at !== null,
                ])->all(),
            'people' => CommsPerson::query()->whereBelongsTo($user)->with('identities')->orderBy('display_name')->get()
                ->map(fn (CommsPerson $person): array => [
                    'display_name' => $person->display_name,
                    'relation' => $person->relation->value,
                    'identities' => $person->identities
                        ->sortBy(fn (CommsIdentity $identity): string => $identity->source->value.':'.$identity->external_id)
                        ->values()
                        ->map(fn (CommsIdentity $identity): array => [
                            'source' => $identity->source->value,
                            'external_id' => $identity->external_id,
                            'email' => $identity->email,
                        ])->all(),
                ])->all(),
            'windows' => CommsWindow::query()->whereBelongsTo($user)->orderBy('id')->get()
                ->map(fn (CommsWindow $window): array => [
                    'source' => $window->source,
                    'group_ref' => $window->group_ref,
                    'kind' => $window->kind,
                    'state' => $window->state,
                    'message_ids' => $window->message_ids,
                ])->all(),
            'segments' => CommsSegment::query()->whereBelongsTo($user)->orderBy('id')->get()
                ->map(fn (CommsSegment $segment): array => [
                    'window_id' => $segment->window_id,
                    'restated' => $segment->restated,
                    'unsegmented' => $segment->unsegmented,
                    'message_ids' => $segment->message_ids,
                    'new_message_ids' => $segment->new_message_ids,
                ])->all(),
            'link_decisions' => CommsLinkDecision::query()->whereBelongsTo($user)->orderBy('id')->get()
                ->map(fn (CommsLinkDecision $decision): array => [
                    'segment_id' => $decision->segment_id,
                    'outcome' => $decision->outcome,
                    'certainty' => $decision->certainty,
                    'agreement' => $decision->agreement,
                    'quote' => $decision->quote,
                ])->all(),
            'topic_search' => CommsTopicSearch::query()->whereBelongsTo($user)->orderBy('id')->get()
                ->map(fn (CommsTopicSearch $topic): array => [
                    'topic_subject' => $topic->topic_subject,
                    'document' => $topic->document,
                    'embedding_hash' => $topic->embedding_hash,
                ])->all(),
            'topic_aliases' => CommsTopicAlias::query()->whereBelongsTo($user)->orderBy('id')->get()
                ->map(fn (CommsTopicAlias $alias): array => [
                    'topic_subject' => $alias->topic_subject,
                    'alias' => $alias->alias,
                    'alias_normalized' => $alias->alias_normalized,
                ])->all(),
            'urgent_rules' => CommsUrgentRule::query()->whereBelongsTo($user)->orderBy('id')->get()
                ->map(fn (CommsUrgentRule $rule): array => [
                    'kind' => $rule->kind,
                    'ref' => $rule->ref,
                    'sources' => $rule->sources,
                    'active' => $rule->active,
                ])->all(),
            'golden_labels' => CommsGoldenLabel::query()->whereBelongsTo($user)->orderBy('id')->get()
                ->map(fn (CommsGoldenLabel $label): array => [
                    'kind' => $label->kind,
                    'label' => $label->label,
                    'message_id' => $label->message_id,
                    'evidence_event_ids' => $label->evidence_event_ids,
                ])->all(),
            'reconcile_changes' => CommsReconcileChange::query()->whereBelongsTo($user)->orderBy('id')->get()
                ->map(fn (CommsReconcileChange $change): array => [
                    'run_date' => $change->run_date?->toDateString(),
                    'op' => $change->op,
                    'disposition' => $change->disposition,
                    'payload' => $change->payload,
                ])->all(),
            'card_views' => CommsCardView::query()->whereBelongsTo($user)->orderBy('id')->get()
                ->map(fn (CommsCardView $view): array => [
                    'intent_id' => $view->intent_id,
                    'seen_at' => $view->seen_at?->toIso8601String(),
                ])->all(),
        ];

        return array_filter($captured);
    }

    public function apply(CommsEvent $event): void {}
}
