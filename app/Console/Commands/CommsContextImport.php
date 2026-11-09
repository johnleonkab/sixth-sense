<?php

namespace App\Console\Commands;

use App\Comms\Context\Audience;
use App\Comms\Context\ContextWriter;
use App\Comms\Context\FactOrigin;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('comms:context-import {user : The user id}')]
#[Description('Copy lessons, the nightly digest, Slack attention and answered questions into the context store')]
class CommsContextImport extends Command
{
    public function handle(ContextWriter $context): int
    {
        $user = User::query()->find($this->argument('user'));

        if (! $user instanceof User) {
            $this->components->error('User not found.');

            return self::FAILURE;
        }

        $operations = [];

        foreach ($user->intentLessons()->latest('id')->limit(200)->get() as $lesson) {
            $operations[] = [
                'op' => 'add',
                'kind' => 'rule',
                'subject' => filled($lesson->about) ? 'person:'.mb_strtolower((string) $lesson->about) : 'user',
                'attribute' => 'rule',
                'value' => ['text' => $lesson->body, 'topic' => $lesson->topic],
                'audiences' => $lesson->layer === null ? [] : [Audience::fromLayer($lesson->layer)->value],
                'origin' => FactOrigin::Correction->value,
            ];
        }

        $digest = $user->workContext;
        if ($digest !== null && filled($digest->summary)) {
            $operations[] = [
                'op' => 'set',
                'kind' => 'summary',
                'subject' => 'user',
                'attribute' => 'summary',
                'value' => ['text' => $digest->summary],
                'audiences' => [],
                'origin' => FactOrigin::Inferred->value,
            ];
        }

        $profile = $user->slackAttentionProfile;
        if ($profile !== null && filled($profile->body)) {
            $operations[] = [
                'op' => 'set',
                'kind' => 'channel',
                'subject' => 'slack',
                'attribute' => 'profile',
                'value' => ['text' => $profile->body],
                'audiences' => [Audience::Triage->value],
                'origin' => FactOrigin::Observed->value,
            ];
        }

        foreach ($user->knowledgeGaps()->where('status', 'answered')->limit(100)->get() as $gap) {
            $operations[] = [
                'op' => 'set',
                'kind' => 'term',
                'subject' => 'term:'.$gap->subject_key,
                'attribute' => 'meaning',
                'value' => ['text' => (string) $gap->answer],
                'audiences' => [],
                'origin' => FactOrigin::User->value,
            ];
        }

        if ($operations === []) {
            $this->components->info('Nothing to import.');

            return self::SUCCESS;
        }

        $context->record($user, FactOrigin::User, $operations);
        $this->components->info(count($operations).' facts imported.');

        return self::SUCCESS;
    }
}
