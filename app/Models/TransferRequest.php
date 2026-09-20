<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransferRequestStatus;
use App\Enums\TransferType;
use Database\Factories\TransferRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ownership / nominee transfer request (M10). TRF-000001. Completion is the
 * only thing that mutates ownership history, transactionally. Historical buyer
 * data is never edited to represent a transfer.
 *
 * @property TransferRequestStatus $status
 * @property TransferType $transfer_type
 */
class TransferRequest extends Model
{
    /** @use HasFactory<TransferRequestFactory> */
    use HasFactory;

    public const SEQUENCE_KEY = 'transfer_request';

    protected $fillable = [
        'request_number', 'booking_id', 'plot_id', 'new_plot_id', 'transfer_type', 'status',
        'current_buyer_id', 'new_buyer_id', 'reason', 'financial_snapshot', 'financial_waiver_reason',
        'requested_at', 'requested_by', 'submitted_at', 'review_started_at',
        'approved_at', 'approved_by', 'rejected_at', 'rejected_by', 'rejection_reason',
        'completed_at', 'completed_by', 'cancelled_at', 'cancellation_reason',
        'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'booking_id' => 'integer',
            'plot_id' => 'integer',
            'new_plot_id' => 'integer',
            'transfer_type' => TransferType::class,
            'status' => TransferRequestStatus::class,
            'current_buyer_id' => 'integer',
            'new_buyer_id' => 'integer',
            'financial_snapshot' => 'array',
            'requested_at' => 'datetime',
            'requested_by' => 'integer',
            'submitted_at' => 'datetime',
            'review_started_at' => 'datetime',
            'approved_at' => 'datetime',
            'approved_by' => 'integer',
            'rejected_at' => 'datetime',
            'rejected_by' => 'integer',
            'completed_at' => 'datetime',
            'completed_by' => 'integer',
            'cancelled_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    public static function formatCode(int $number): string
    {
        return 'TRF-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** The plot at request time — the OLD plot for a plot transfer, unchanged for every other type. @return BelongsTo<Plot, $this> */
    public function plot(): BelongsTo
    {
        return $this->belongsTo(Plot::class);
    }

    /** The target plot — only set for a PLOT_TRANSFER. @return BelongsTo<Plot, $this> */
    public function newPlot(): BelongsTo
    {
        return $this->belongsTo(Plot::class, 'new_plot_id');
    }

    /** @return BelongsTo<Buyer, $this> */
    public function currentBuyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class, 'current_buyer_id');
    }

    /** @return BelongsTo<Buyer, $this> */
    public function newBuyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class, 'new_buyer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** @param  Builder<TransferRequest>  $query */
    public function scopeStatus(Builder $query, TransferRequestStatus|string|null $status): void
    {
        if ($status !== null && $status !== '') {
            $query->where('status', $status instanceof TransferRequestStatus ? $status->value : $status);
        }
    }

    public function canTransitionTo(TransferRequestStatus $target): bool
    {
        return $this->status->canTransitionTo($target);
    }

    public function isCompleted(): bool
    {
        return $this->status === TransferRequestStatus::Completed;
    }
}
