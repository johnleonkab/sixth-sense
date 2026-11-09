<?php

namespace App\Models\Comms;

use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Models\User;
use Database\Factories\Comms\CommsEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One appended fact. Rows are inserted and then left alone, except the
 * consumer may set processed_at. Replay reads them in id order.
 */
#[Fillable([
    'user_id',
    'type',
    'source',
    'external_id',
    'revision',
    'payload',
    'normalized',
    'causation_id',
    'sensitive',
    'occurred_at',
    'processed_at',
])]
class CommsEvent extends Model
{
    /** @use HasFactory<CommsEventFactory> */
    use HasFactory;

    protected $table = 'comms_events';

    public const CREATED_AT = 'recorded_at';

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => EventType::class,
            'source' => Source::class,
            'payload' => 'array',
            'normalized' => 'array',
            'sensitive' => 'boolean',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function causation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'causation_id');
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        if ($this->exists && array_keys($this->getDirty()) !== ['processed_at']) {
            throw new LogicException('The event log is append-only.');
        }

        return parent::save($options);
    }

    public function delete(): never
    {
        throw new LogicException('The event log is append-only.');
    }
}
