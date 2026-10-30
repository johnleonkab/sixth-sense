<?php

namespace App\Http\Requests;

use App\Voice\Tools\ToolContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class RunVoiceToolRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'arguments' => ['present', 'array'],
            'timezone' => ['nullable', 'string', 'timezone:all'],
            'origin' => ['nullable', 'in:voice,screen'],
        ];
    }

    /**
     * Arguments the model sent. Each tool validates its own.
     *
     * @return array<string, mixed>
     */
    public function arguments(): array
    {
        return $this->validated('arguments');
    }

    /**
     * Who chose the call: the voice model, or the person from the screen.
     */
    public function origin(): string
    {
        return $this->validated('origin') ?? ToolContext::SCREEN;
    }

    /**
     * The current moment in the browser's timezone, or the app's when it is not sent.
     */
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->validated('timezone') ?? config('app.timezone'));
    }
}
