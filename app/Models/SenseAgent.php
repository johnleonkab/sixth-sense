<?php

namespace App\Models;

use Database\Factories\SenseAgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An agent copied from .claude/agents/ in the work repository, read as
 * Claude Code reads a subagent. The voice reads this table, not GitHub,
 * when it decides who should take the turn.
 */
#[Fillable(['slug', 'name', 'description', 'instructions', 'skills', 'model', 'source_path', 'source_sha'])]
class SenseAgent extends Model
{
    /** @use HasFactory<SenseAgentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'skills' => 'array',
        ];
    }

    /**
     * The system prompt plus the skills listed in `skills:`, preloaded in
     * full as Claude Code does. Skills that are not synced are skipped.
     */
    public function briefing(): string
    {
        $instructions = $this->instructions !== '' ? $this->instructions : $this->description;
        $names = array_values(array_filter($this->skills ?? [], 'is_string'));

        if ($names === []) {
            return $instructions;
        }

        $skills = $this->user->senseSkills()
            ->whereIn('name', $names)
            ->get()
            ->sortBy(fn (SenseSkill $skill): int|false => array_search($skill->name, $names, true));

        $blocks = $skills->map(fn (SenseSkill $skill): string => "# Skill {$skill->name}\n\n".$skill->briefing())->all();

        return $blocks === [] ? $instructions : trim($instructions."\n\n".implode("\n\n", $blocks));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
