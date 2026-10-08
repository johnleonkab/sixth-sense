<?php

namespace App\Voice\Tools;

use App\Models\User;

/**
 * A function the voice assistant can call. Declared once here and translated
 * by each voice provider into its own format.
 */
interface VoiceTool
{
    /**
     * Function name the model calls (snake_case).
     */
    public function name(): string;

    /**
     * When the model should, and should not, call it.
     */
    public function description(): string;

    /**
     * Arguments, as a JSON Schema object.
     *
     * @return array<string, mixed>
     */
    public function parameters(): array;

    /**
     * Laravel validation rules for the arguments.
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * Run the tool with validated arguments.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function handle(User $user, array $arguments, ToolContext $context): ToolResult;
}
