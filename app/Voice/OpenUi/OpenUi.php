<?php

namespace App\Voice\OpenUi;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Views composed from the OpenUI blocks of resources/js/canvas/openui/spec.ts.
 * The prompt the voice follows is generated from those same blocks by
 * `npm run openui:prompt`; this class reads it, and writes OpenUI Lang for
 * views Laravel builds itself.
 */
class OpenUi
{
    /**
     * How to compose a view, as the voice is told.
     */
    public static function prompt(): string
    {
        $path = resource_path('openui/prompt.md');

        return is_file($path) ? trim((string) file_get_contents($path)) : '';
    }

    /**
     * How to use live data in a view (Query, $variables, the functions and
     * every dataset), for the voice to ask for when it needs it.
     */
    public static function dataGuide(): string
    {
        $path = resource_path('openui/data-guide.md');

        return is_file($path) ? trim((string) file_get_contents($path)) : '';
    }

    /**
     * One OpenUI Lang value: a string, number, boolean, null, list, object
     * (an array with keys; nulls are left out) or a reference to another
     * statement (Ref).
     */
    public static function value(mixed $value): string
    {
        return match (true) {
            $value instanceof Ref => $value->name,
            is_string($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            is_int($value), is_float($value) => (string) (is_float($value) ? round($value, 2) : $value),
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_array($value) && array_is_list($value) => '['.implode(', ', array_map(self::value(...), $value)).']',
            is_array($value) => self::object(array_filter($value, fn (mixed $item): bool => $item !== null)),
            default => json_encode((string) $value, JSON_UNESCAPED_UNICODE),
        };
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function object(array $value): string
    {
        return '{'.implode(', ', array_map(fn (string $key, mixed $item): string => "{$key}: ".self::value($item), array_keys($value), $value)).'}';
    }

    /**
     * The last composed view the user saw, whole, so it can be saved by name.
     *
     * @param  array{title: string, source: string, ui: string, items: list<array<string, mixed>>}  $view
     */
    public static function rememberView(User $user, array $view): void
    {
        Cache::put("openui-view:{$user->id}", $view, now()->addHours(12));
    }

    /**
     * @return array{title: string, source: string, ui: string, items: list<array<string, mixed>>}|null
     */
    public static function lastView(User $user): ?array
    {
        return Cache::get("openui-view:{$user->id}");
    }

    /**
     * The items a user's last composed view can open, so a view the
     * assistant rewrites keeps them.
     *
     * @param  list<array{n: int, title: string, tool: string, args: array<string, mixed>}>  $items
     */
    public static function rememberItems(User $user, array $items): void
    {
        Cache::put("openui-items:{$user->id}", $items, now()->addHours(12));
    }

    /**
     * @return list<array{n: int, title: string, tool: string, args: array<string, mixed>}>
     */
    public static function lastItems(User $user): array
    {
        return Cache::get("openui-items:{$user->id}", []);
    }

    /**
     * A block call: Metric("Usuarios", "1.204").
     *
     * @param  list<mixed>  $arguments  Positional, as the block's signature orders them.
     */
    public static function call(string $block, array $arguments): string
    {
        while ($arguments !== [] && end($arguments) === null) {
            array_pop($arguments);
        }

        return $block.'('.implode(', ', array_map(self::value(...), $arguments)).')';
    }
}
