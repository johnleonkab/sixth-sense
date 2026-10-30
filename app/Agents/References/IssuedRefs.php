<?php

namespace App\Agents\References;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * The ids the server has handed out to a person lately: every email,
 * message, thread, event, file or link a tool returned or put on screen. A
 * model may point at one of these, never at one it made up.
 *
 * Ids that live in this app's database (an alert, a task, a proposal) are not
 * kept here: the tools look them up among the person's own, which already
 * proves they exist.
 */
class IssuedRefs
{
    /**
     * Arguments that carry an opaque id from an external service: only valid
     * when the server issued it.
     *
     * @var list<string>
     */
    public const STRICT = ['email_id', 'message_id', 'thread_id', 'event_id', 'file_id', 'link_id'];

    /**
     * Keys under which tools and canvases hand ids out.
     *
     * @var list<string>
     */
    private const ISSUING = [...self::STRICT, 'ref', 'thread_ref', 'focus_ref', 'external_id', 'event_ref', 'sent_ref'];

    /** How many ids are kept per person: the newest. */
    private const KEPT = 5000;

    private const HOURS = 12;

    /**
     * Note every id a tool's answer hands out.
     *
     * @param  array<mixed>  ...$answers  Its output, its canvas, what goes beside it.
     */
    public function remember(User $user, array ...$answers): void
    {
        $found = [];

        foreach ($answers as $answer) {
            $this->collect($answer, $found, 0);
        }

        if ($found === []) {
            return;
        }

        $known = Cache::get($this->key($user), []);
        $known = array_slice(array_values(array_unique([...array_diff($known, $found), ...$found])), -self::KEPT);

        Cache::put($this->key($user), $known, now()->addHours(self::HOURS));
    }

    public function known(User $user, string $id): bool
    {
        return in_array($id, Cache::get($this->key($user), []), true);
    }

    /**
     * The first strict argument whose id the server never issued, if any.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function unknown(User $user, array $arguments): ?string
    {
        foreach (self::STRICT as $key) {
            $value = $arguments[$key] ?? null;

            if (is_string($value) && $value !== '' && ! $this->known($user, $value)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param  array<string>  $found
     */
    private function collect(mixed $value, array &$found, int $depth): void
    {
        if (! is_array($value) || $depth > 8) {
            return;
        }

        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, self::ISSUING, true) && is_string($item) && $item !== '' && mb_strlen($item) <= 300) {
                $found[] = $item;
            }

            $this->collect($item, $found, $depth + 1);
        }
    }

    private function key(User $user): string
    {
        return "issued-refs:{$user->id}";
    }
}
