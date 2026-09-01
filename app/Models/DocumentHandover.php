<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HandoverStatus;
use Database\Factories\DocumentHandoverFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Document handover (M9). One per booking, created when the registry case
 * completes. A completed handover is terminal.
 *
 * @property HandoverStatus $status
 */
class DocumentHandover extends Model
{
    /** @use HasFactory<DocumentHandoverFactory> */
    use HasFactory;

    protected $fillable = [
        'booking_id', 'registry_case_id', 'document_id', 'status', 'scheduled_at', 'handover_date',
        'received_by', 'handover_notes', 'completed_by', 'completed_at',
        'reversed_by', 'reversed_at', 'reversal_reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'booking_id' => 'integer',
            'registry_case_id' => 'integer',
            'document_id' => 'integer',
            'status' => HandoverStatus::class,
            'scheduled_at' => 'datetime',
            'handover_date' => 'date',
            'completed_by' => 'integer',
            'completed_at' => 'datetime',
            'reversed_by' => 'integer',
            'reversed_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<RegistryCase, $this> */
    public function registryCase(): BelongsTo
    {
        return $this->belongsTo(RegistryCase::class);
    }

    /** The versioned HANDOVER_ACK document slot on the booking. @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function isComplete(): bool
    {
        return $this->status === HandoverStatus::HandedOver;
    }

    public function canTransitionTo(HandoverStatus $target): bool
    {
        return $this->status->canTransitionTo($target);
    }
}
