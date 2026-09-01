<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CollectionFollowUpOutcome;
use Database\Factories\CollectionFollowUpFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A logged collection interaction (M8). Not the M5 sales lead follow-up.
 *
 * @property CollectionFollowUpOutcome|null $outcome
 */
class CollectionFollowUp extends Model
{
    /** @use HasFactory<CollectionFollowUpFactory> */
    use HasFactory;

    protected $fillable = [
        'collection_case_id', 'booking_id', 'installment_id', 'assigned_to',
        'follow_up_at', 'outcome', 'notes', 'completed_at', 'next_follow_up_at',
        'created_by', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'collection_case_id' => 'integer',
            'booking_id' => 'integer',
            'installment_id' => 'integer',
            'assigned_to' => 'integer',
            'created_by' => 'integer',
            'follow_up_at' => 'datetime',
            'completed_at' => 'datetime',
            'next_follow_up_at' => 'datetime',
            'outcome' => CollectionFollowUpOutcome::class,
        ];
    }

    /** @return BelongsTo<CollectionCase, $this> */
    public function collectionCase(): BelongsTo
    {
        return $this->belongsTo(CollectionCase::class);
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<Installment, $this> */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(Installment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    public function isOverdue(): bool
    {
        return ! $this->isCompleted() && $this->follow_up_at->isPast();
    }
}
