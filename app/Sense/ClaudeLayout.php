<?php

namespace App\Sense;

/**
 * Where Claude Code keeps agents, skills and memory in a repository, and how
 * it reads their front matter. The work archive follows the same layout, so
 * the same repository works in Claude Code and in sixth-sense.
 *
 * @see https://code.claude.com/docs/en/sub-agents
 * @see https://code.claude.com/docs/en/skills
 */
class ClaudeLayout
{
    public const MEMORY = 'CLAUDE.md';

    public const AGENTS = '.claude/agents/';

    public const SKILLS = '.claude/skills/';

    /**
     * Agents are any markdown under .claude/agents/, subfolders included.
     */
    public static function isAgent(string $path): bool
    {
        return str_starts_with($path, self::AGENTS) && str_ends_with($path, '.md');
    }

    /**
     * The folder of the skill whose SKILL.md this is, or null.
     */
    public static function skillFolder(string $path): ?string
    {
        return preg_match('#^\.claude/skills/([^/]+)/SKILL\.md$#', $path, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * The folder of the skill a supporting file belongs to, or null.
     */
    public static function skillFileFolder(string $path): ?string
    {
        return preg_match('#^\.claude/skills/([^/]+)/(?!SKILL\.md$).+$#', $path, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * Whether a change to this path changes what the voice can use.
     */
    public static function touchesRuntime(string $path): bool
    {
        return $path === self::MEMORY || self::isAgent($path) || str_starts_with($path, self::SKILLS);
    }

    public static function agentPath(string $name): string
    {
        return self::AGENTS."{$name}.md";
    }

    public static function skillPath(string $folder): string
    {
        return self::SKILLS."{$folder}/SKILL.md";
    }

    /**
     * Claude Code accepts `tools` and `skills` as a comma-separated string or
     * a YAML list.
     *
     * @return list<string>
     */
    public static function names(mixed $value): array
    {
        $items = match (true) {
            is_string($value) => explode(',', $value),
            is_array($value) => $value,
            default => [],
        };

        $names = [];

        foreach ($items as $item) {
            if (is_string($item) && trim($item) !== '') {
                $names[] = trim($item);
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * A skill's description: `description` plus `when_to_use`, or the first
     * paragraph of the body when it has none, cut where Claude Code cuts it.
     *
     * @param  array<string, mixed>  $front
     */
    public static function skillDescription(array $front, string $body): string
    {
        $description = is_string($front['description'] ?? null) ? trim($front['description']) : '';
        $when = is_string($front['when_to_use'] ?? null) ? trim($front['when_to_use']) : '';

        if ($description === '') {
            foreach (preg_split('/\R\s*\R/', trim($body)) ?: [] as $paragraph) {
                $paragraph = trim((string) preg_replace('/^#+\s*/m', '', $paragraph));

                if ($paragraph !== '') {
                    $description = $paragraph;

                    break;
                }
            }
        }

        return mb_substr(trim($description.($when === '' ? '' : ' '.$when)), 0, 1536);
    }
}
