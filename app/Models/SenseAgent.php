<?php

namespace App\Models;

use Database\Factories\SenseAgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An agent copied from the work repository. The voice reads this table,
 * not GitHub, when it decides who should take the turn.
 */
#[Fillable(['slug', 'name', 'purpose', 'cue', 'instructions', 'references', 'source_path', 'source_sha'])]
class SenseAgent extends Model
{
    /** @use HasFactory<SenseAgentFactory> */
    use HasFactory;

    /**
     * Instructions plus the markdown in agents/{slug}/references/.
     */
    public function briefing(): string
    {
        $instructions = $this->instructions !== '' ? $this->instructions : $this->purpose;
        $references = trim((string) $this->references);

        if ($references === '') {
            return $instructions;
        }

        return trim($instructions."\n\nReferencias del agente:\n\n".$references);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
