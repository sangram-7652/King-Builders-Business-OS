<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChequeStatus;
use App\Enums\PaymentStatus;
use App\Models\Masters\PaymentMode;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property PaymentStatus $status
 * @property ChequeStatus|null $cheque_status
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory, SoftDeletes;

    public const SEQUENCE_KEY = 'payment';

    protected $fillable = [
        'payment_number', 'idempotency_key', 'booking_id', 'payment_mode_id',
        'payment_date', 'amount', 'status', 'reference_number', 'notes',
        'cheque_number', 'cheque_bank_name', 'cheque_date', 'cheque_status',
        'received_by', 'verified_by', 'verified_at',
        'reversed_by', 'reversed_at', 'reversal_reason',
    ];

    protected function casts(): array
    {
        return [
            'booking_id' => 'integer',
            'payment_mode_id' => 'integer',
            'payment_date' => 'date',
            'amount' => 'decimal:2',
            'status' => PaymentStatus::class,
            'cheque_date' => 'date',
            'cheque_status' => ChequeStatus::class,
            'received_by' => 'integer',
            'verified_by' => 'integer',
            'verified_at' => 'datetime',
            'reversed_by' => 'integer',
            'reversed_at' => 'datetime',
        ];
    }

    public static function formatCode(int $number): string
    {
        return 'PAY-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    // --- Relationships -------------------------------------------------

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<PaymentMode, $this> */
    public function paymentMode(): BelongsTo
    {
        return $this->belongsTo(PaymentMode::class);
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /** @return HasOne<Receipt, $this> */
    public function receipt(): HasOne
    {
        return $this->hasOne(Receipt::class);
    }

    /** @return BelongsTo<User, $this> */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** @return BelongsTo<User, $this> */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    // --- Scopes ------------------------------------------------------

    /** @param  Builder<Payment>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term !== '') {
            $query->where(fn (Builder $q) => $q
                ->where('payment_number', 'like', "%{$term}%")
                ->orWhere('reference_number', 'like', "%{$term}%"));
        }
    }

    /** @param  Builder<Payment>  $query */
    public function scopeStatus(Builder $query, PaymentStatus|string|null $status): void
    {
        if ($status !== null && $status !== '') {
            $query->where('status', $status instanceof PaymentStatus ? $status->value : $status);
        }
    }

    /** @param  Builder<Payment>  $query */
    public function scopeSuccessful(Builder $query): void
    {
        $query->where('status', PaymentStatus::Success->value);
    }

    // --- Helpers ---------------------------------------------------

    public function isPending(): bool
    {
        return $this->status === PaymentStatus::Pending;
    }

    public function isSuccessful(): bool
    {
        return $this->status === PaymentStatus::Success;
    }

    public function isReversed(): bool
    {
        return $this->status === PaymentStatus::Reversed;
    }

    public function canTransitionTo(PaymentStatus $target): bool
    {
        return $this->status->canTransitionTo($target);
    }

    public function isCheque(): bool
    {
        return $this->cheque_status !== null
            || ($this->relationLoaded('paymentMode') && (bool) $this->paymentMode?->is_cheque);
    }
}
