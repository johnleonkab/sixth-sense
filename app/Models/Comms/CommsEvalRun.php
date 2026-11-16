<?php

namespace App\Models\Comms;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'kind', 'prompt_versions', 'metrics', 'passed', 'report_path'])]
class CommsEvalRun extends Model
{
    protected $table = 'comms_eval_runs';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'prompt_versions' => 'array',
            'metrics' => 'array',
            'passed' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
