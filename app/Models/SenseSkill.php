<?php

namespace App\Models;

use Database\Factories\SenseSkillFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A skill copied from .claude/skills/{name}/SKILL.md in the work repository.
 * Only its description is always in context; the body loads when it is used,
 * and the other files in its folder are read only when needed.
 */
#[Fillable(['name', 'description', 'instructions', 'files', 'model_invocable', 'source_path', 'source_sha'])]
class SenseSkill extends Model
{
    /** @use HasFactory<SenseSkillFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'files' => 'array',
            'model_invocable' => 'boolean',
        ];
    }

    /**
     * The SKILL.md body, and where its supporting files are.
     */
    public function briefing(): string
    {
        $files = array_values(array_filter($this->files ?? [], 'is_string'));

        if ($files === []) {
            return $this->instructions;
        }

        $list = implode("\n", array_map(fn (string $path): string => "- {$path}", $files));

        return trim($this->instructions."\n\nArchivos de la skill. Léelos con read_sense_file solo cuando hagan falta:\n".$list);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
