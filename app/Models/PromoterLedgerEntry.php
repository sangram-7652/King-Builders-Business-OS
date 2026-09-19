<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PromoterLedgerEntryType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One append-only row in a promoter's financial ledger (advance + commission
 * history). Never edited or deleted — a correction is always a new
 * compensating row. The promoter's advance balance is always DERIVED by
 * summing this table (see {@see \App\Services\Commission\PromoterLedgerService::advanceBalance()}),
 * never trusted from a cached column elsewhere.
 *
 * @property PromoterLedgerEntryType $type
 */
class PromoterLedgerEntry extends Model
{
    /** Append-only with its own `created_at`; no updated_at. */
    const UPDATED_AT = null;

    protected $fillable = [
        'partner_id', 'type', 'booking_id', 'commission_case_id', 'commission_payout_id',
        'gross_commission_amount', 'advance_amount', 'adjustment_amount', 'payable_amount', 'payout_amount',
        'balance_after', 'reference', 'description',
        'reversed_at', 'reversal_entry_id', 'created_by', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'partner_id' => 'integer',
            'type' => PromoterLedgerEntryType::class,
            'booking_id' => 'integer',
            'commission_case_id' => 'integer',
            'commission_payout_id' => 'integer',
            'gross_commission_amount' => 'decimal:2',
            'advance_amount' => 'decimal:2',
            'adjustment_amount' => 'decimal:2',
            'payable_amount' => 'decimal:2',
            'payout_amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'reversed_at' => 'datetime',
            'reversal_entry_id' => 'integer',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<CommissionCase, $this> */
    public function commissionCase(): BelongsTo
    {
        return $this->belongsTo(CommissionCase::class);
    }

    /** @return BelongsTo<CommissionPayout, $this> */
    public function commissionPayout(): BelongsTo
    {
        return $this->belongsTo(CommissionPayout::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<PromoterLedgerEntry, $this> */
    public function reversalEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_entry_id');
    }

    /** A `commission` row whose advance adjustment has not (yet) been compensated. */
    public function isActiveAdjustment(): bool
    {
        return $this->type === PromoterLedgerEntryType::Commission
            && $this->reversed_at === null
            && $this->adjustment_amount !== null
            && bccomp((string) $this->adjustment_amount, '0', 2) > 0;
    }
}
