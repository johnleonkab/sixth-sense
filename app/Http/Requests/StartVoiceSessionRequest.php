<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class StartVoiceSessionRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'timezone' => ['nullable', 'string', 'timezone:all'],
        ];
    }

    /**
     * The current moment in the browser's timezone, or the app's when it is not sent.
     */
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->validated('timezone') ?? config('app.timezone'));
    }
}
