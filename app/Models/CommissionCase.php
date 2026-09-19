<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommissionCaseEventType;
use App\Enums\CommissionCaseStatus;
use Database\Factories\CommissionCaseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A promoter's commission obligation on one booking (M14.4). CMN-000001.
 * One per (booking, partner) — at most one per booking, since a booking has at
 * most one promoter. The authoritative figure is the immutable
 * {@see CommissionCalculation} snapshot `current_calculation_id` points at.
 *
 * `commission_amount` is the GROSS commission earned; `advance_adjusted_amount`
 * is how much of it was automatically consumed against the promoter's
 * outstanding advance (see {@see \App\Services\Commission\PromoterLedgerService});
 * `payable_amount` = commission_amount − advance_adjusted_amount is what a
 * payout may actually pay out. Never conflate gross with payable.
 *
 * @property CommissionCaseStatus $status
 */
class CommissionCase extends Model
{
    /** @use HasFactory<CommissionCaseFactory> */
    use HasFactory;

    public const SEQUENCE_KEY = 'commission_case';

    protected $fillable = [
        'case_number', 'booking_id', 'partner_id', 'booking_partner_attribution_id',
        'current_calculation_id', 'status',
        'is_eligible', 'eligibility_reason', 'eligibility_checked_at',
        'commission_amount', 'advance_adjusted_amount', 'payable_amount', 'paid_amount', 'clawback_amount',
        'generated_at', 'generated_by', 'approved_at', 'approved_by',
        'held_at', 'hold_reason',
        'cancelled_at', 'cancellation_reason',
        'reversed_at', 'reversal_reason', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'booking_id' => 'integer',
            'partner_id' => 'integer',
            'booking_partner_attribution_id' => 'integer',
            'current_calculation_id' => 'integer',
            'status' => CommissionCaseStatus::class,
            'is_eligible' => 'boolean',
            'eligibility_checked_at' => 'datetime',
            'commission_amount' => 'decimal:2',
            'advance_adjusted_amount' => 'decimal:2',
            'payable_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'clawback_amount' => 'decimal:2',
            'generated_at' => 'datetime',
            'generated_by' => 'integer',
            'approved_at' => 'datetime',
            'approved_by' => 'integer',
            'held_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public static function formatCode(int $number): string
    {
        return 'CMN-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    // --- Relationships -------------------------------------------------

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return BelongsTo<BookingPartnerAttribution, $this> */
    public function attribution(): BelongsTo
    {
        return $this->belongsTo(BookingPartnerAttribution::class, 'booking_partner_attribution_id');
    }

    /** @return BelongsTo<CommissionCalculation, $this> */
    public function currentCalculation(): BelongsTo
    {
        return $this->belongsTo(CommissionCalculation::class, 'current_calculation_id');
    }

    /** @return HasMany<CommissionCalculation, $this> */
    public function calculations(): HasMany
    {
        return $this->hasMany(CommissionCalculation::class)->orderByDesc('sequence');
    }

    /** @return HasMany<CommissionCaseEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(CommissionCaseEvent::class)->latest('id');
    }

    /** @return HasMany<CommissionPayout, $this> */
    public function payouts(): HasMany
    {
        return $this->hasMany(CommissionPayout::class)->latest('id');
    }

    /** This case's ledger footprint (its `commission` row + any reversal of it). @return HasMany<PromoterLedgerEntry, $this> */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(PromoterLedgerEntry::class)->orderBy('id');
    }

    /** Payouts that count toward `paid_amount` (not voided). @return HasMany<CommissionPayout, $this> */
    public function recordedPayouts(): HasMany
    {
        return $this->hasMany(CommissionPayout::class)->where('status', 'recorded');
    }

    /** @return BelongsTo<User, $this> */
    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // --- Scopes ------------------------------------------------------

    /** @param  Builder<CommissionCase>  $query */
    public function scopeStatus(Builder $query, CommissionCaseStatus|string|null $status): void
    {
        if ($status !== null && $status !== '') {
            $query->where('status', $status instanceof CommissionCaseStatus ? $status->value : $status);
        }
    }

    /** @param  Builder<CommissionCase>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNotIn('status', [CommissionCaseStatus::Cancelled->value, CommissionCaseStatus::Reversed->value]);
    }

    // --- Helpers ---------------------------------------------------

    public function canTransitionTo(CommissionCaseStatus $target): bool
    {
        return $this->status->canTransitionTo($target);
    }

    public function isRecalculable(): bool
    {
        return $this->status->isRecalculable();
    }

    /** The outstanding PAYABLE commission still to be paid out (0 once fully paid; never the gross figure). */
    public function outstandingAmount(): string
    {
        return bcsub((string) $this->payable_amount, (string) $this->paid_amount, 2);
    }

    /**
     * Append one row to the case timeline.
     *
     * @param  array<string, scalar|null>  $properties  never PII
     */
    public function recordEvent(CommissionCaseEventType $type, string $description, array $properties = [], ?User $causer = null): CommissionCaseEvent
    {
        return $this->events()->create([
            'type' => $type,
            'description' => $description,
            'properties' => $properties ?: null,
            'causer_id' => ($causer ?? auth()->user())?->id,
            'created_at' => now(),
        ]);
    }
}
