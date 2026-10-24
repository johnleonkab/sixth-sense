<?php

namespace App\Ai;

use App\Jobs\RunScene;
use App\Jobs\RunSceneStep;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Eloquent\Model;
use ReflectionObject;
use ReflectionProperty;
use Throwable;

/**
 * The person a queued job is working for, so their key is used and then forgotten.
 */
class JobOwner
{
    public function idFor(Job $job): ?int
    {
        try {
            $command = unserialize($job->payload()['data']['command'] ?? '');
        } catch (Throwable) {
            return null;
        }

        if (! is_object($command)) {
            return null;
        }

        if ($command instanceof RunScene || $command instanceof RunSceneStep) {
            return Scene::query()->find($command->sceneId)?->user_id;
        }

        try {
            $properties = (new ReflectionObject($command))->getProperties(ReflectionProperty::IS_PUBLIC);
        } catch (Throwable) {
            return null;
        }

        foreach ($properties as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $id = $this->userId($property->getValue($command));

            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    private function userId(mixed $value): ?int
    {
        if ($value instanceof User) {
            return $value->getKey();
        }

        if ($value instanceof Model && is_numeric($value->getAttribute('user_id'))) {
            return (int) $value->getAttribute('user_id');
        }

        return null;
    }
}
