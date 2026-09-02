<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PossessionHandoverStatus;
use Database\Factories\PossessionHandoverFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Physical possession handover to the customer (M10). One per possession case.
 * A completed handover is terminal.
 *
 * @property PossessionHandoverStatus $status
 */
class PossessionHandover extends Model
{
    /** @use HasFactory<PossessionHandoverFactory> */
    use HasFactory;

    protected $fillable = [
        'possession_case_id', 'booking_id', 'status', 'handover_date',
        'received_by', 'receiver_identity', 'receiver_relation', 'remarks',
        'acknowledgement_document_id', 'completed_by', 'completed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'possession_case_id' => 'integer',
            'booking_id' => 'integer',
            'status' => PossessionHandoverStatus::class,
            'handover_date' => 'date',
            'acknowledgement_document_id' => 'integer',
            'completed_by' => 'integer',
            'completed_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<PossessionCase, $this> */
    public function possessionCase(): BelongsTo
    {
        return $this->belongsTo(PossessionCase::class);
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function acknowledgementDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'acknowledgement_document_id');
    }

    /** @return BelongsTo<User, $this> */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function isComplete(): bool
    {
        return $this->status === PossessionHandoverStatus::Completed;
    }

    public function canTransitionTo(PossessionHandoverStatus $target): bool
    {
        return $this->status->canTransitionTo($target);
    }
}
