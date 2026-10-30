<?php

namespace App\Voice\OpenUi\Data;

use App\Models\User;

/**
 * Rows a composed view can ask for while it is on screen, with OpenUI's
 * Query(): flat records and what each field is. Read only, always: a
 * dataset never changes anything in a service.
 *
 * Any source becomes chartable by implementing this, with no new chart.
 */
interface Dataset
{
    /** The name a view uses in Query("name", …). */
    public function name(): string;

    /** What it returns, as the assistant is told. */
    public function description(): string;

    /**
     * The arguments, as JSON Schema.
     *
     * @return array<string, mixed>
     */
    public function parameters(): array;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * The fields every row has: name and type (text, number or date).
     *
     * @return array<string, 'text'|'number'|'date'>
     */
    public function fields(): array;

    /**
     * @param  array<string, mixed>  $arguments  Already validated.
     * @return list<array<string, string|int|float|null>>
     */
    public function rows(User $user, array $arguments, string $timezone): array;
}
