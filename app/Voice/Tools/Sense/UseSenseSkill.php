<?php

namespace App\Voice\Tools\Sense;

use App\Models\User;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Loads one skill from the work archive, as Claude Code loads a skill when
 * its description fits: the SKILL.md body, and where its other files are.
 */
class UseSenseSkill implements VoiceTool
{
    public function name(): string
    {
        return 'use_sense_skill';
    }

    public function description(): string
    {
        return 'Carga una skill del archivo cuando el tema encaja con su description. Devuelve su SKILL.md: síguelo. '
            .'Sus otros archivos se leen con read_sense_file solo si hacen falta.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'name de la skill.'],
            ],
            'required' => ['name'],
        ];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $name = trim((string) $arguments['name']);
        $skill = $user->senseSkills()->where('name', $name)->first();

        if ($skill === null) {
            return ToolResult::error("No hay una skill «{$name}». Sincroniza el archivo si acabas de crearla.");
        }

        return new ToolResult([
            'skill' => $skill->name,
            'instructions' => $skill->briefing(),
        ]);
    }
}
