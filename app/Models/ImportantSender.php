<?php

namespace App\Models;

use Database\Factories\ImportantSenderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Gmail sender the person marked as important: their mail is an aviso.
 */
#[Fillable(['email', 'name'])]
class ImportantSender extends Model
{
    /** @use HasFactory<ImportantSenderFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
