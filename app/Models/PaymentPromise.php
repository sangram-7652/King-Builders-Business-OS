<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PromiseStatus;
use Database\Factories\PaymentPromiseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A promise to pay (M8). Never a payment; never affects the M7 paid amount.
 *
 * @property PromiseStatus $status
 */
class PaymentPromise extends Model
{
    /** @use HasFactory<PaymentPromiseFactory> */
    use HasFactory;

    protected $fillable = [
        'collection_case_id', 'booking_id', 'installment_id',
        'promised_amount', 'outstanding_at_creation', 'promise_date', 'status', 'notes',
        'created_by', 'fulfilled_at', 'fulfilled_by_payment_id',
        'broken_at', 'cancelled_at', 'cancelled_by', 'cancellation_reason', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'collection_case_id' => 'integer',
            'booking_id' => 'integer',
            'installment_id' => 'integer',
            'promised_amount' => 'decimal:2',
            'outstanding_at_creation' => 'decimal:2',
            'promise_date' => 'date',
            'status' => PromiseStatus::class,
            'created_by' => 'integer',
            'fulfilled_at' => 'datetime',
            'fulfilled_by_payment_id' => 'integer',
            'broken_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'cancelled_by' => 'integer',
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
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Payment, $this> */
    public function fulfilledByPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'fulfilled_by_payment_id');
    }

    /** @param  Builder<PaymentPromise>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', PromiseStatus::Open->value);
    }

    public function isOpen(): bool
    {
        return $this->status === PromiseStatus::Open;
    }

    public function isKept(): bool
    {
        return $this->status === PromiseStatus::Kept;
    }

    public function isBroken(): bool
    {
        return $this->status === PromiseStatus::Broken;
    }
}
