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
 * A partner's commission obligation on one booking (M14.4). CMN-000001.
 * One per (booking, partner). The authoritative figure is the immutable
 * {@see CommissionCalculation} snapshot `current_calculation_id` points at.
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
        'commission_scheme_id', 'commission_rule_id', 'current_calculation_id', 'status',
        'is_eligible', 'eligibility_reason', 'eligibility_checked_at',
        'commission_amount', 'paid_amount', 'clawback_amount',
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
            'commission_scheme_id' => 'integer',
            'commission_rule_id' => 'integer',
            'current_calculation_id' => 'integer',
            'status' => CommissionCaseStatus::class,
            'is_eligible' => 'boolean',
            'eligibility_checked_at' => 'datetime',
            'commission_amount' => 'decimal:2',
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

    /** @return BelongsTo<CommissionScheme, $this> */
    public function scheme(): BelongsTo
    {
        return $this->belongsTo(CommissionScheme::class, 'commission_scheme_id');
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

    /** The outstanding commission still to be paid (0 once fully paid). */
    public function outstandingAmount(): string
    {
        return bcsub((string) $this->commission_amount, (string) $this->paid_amount, 2);
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
