<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\Booking;
use App\Models\BookingPartnerAttribution;
use App\Models\CommissionCalculation;
use App\Models\CommissionCase;
use App\Models\Partner;
use App\Models\User;
use App\Support\Money;

/**
 * Computes a promoter's flat commission — Booking final_amount × commission
 * rate — applies it against the promoter's advance via
 * {@see PromoterLedgerService}, and appends one immutable
 * {@see CommissionCalculation} row to the case (M14.4). Never mutates an
 * existing calculation row — recalculating writes a new sequence.
 *
 * The rate used is the one SNAPSHOTTED on the booking's attribution at the
 * moment the promoter was attached to it (`$attribution->commission_percentage`)
 * — never the partner's current master rate, so a booking's commission never
 * silently drifts if the promoter's profile rate changes later. Falls back to
 * the partner's live rate only for attribution rows written before this
 * snapshot existed (nullable column, no historical backfill invented).
 *
 * Must be called inside the caller's transaction.
 */
class CommissionCaseWriter
{
    public function __construct(private readonly PromoterLedgerService $ledger) {}

    public function write(CommissionCase $case, Partner $partner, Booking $booking, BookingPartnerAttribution $attribution, User $actor): CommissionCalculation
    {
        $basisAmount = Money::of($booking->final_amount);
        $rate = (string) ($attribution->commission_percentage ?? $partner->commission_percentage ?? '0');
        $gross = Money::of($rate)->percentageOf($basisAmount);

        $entry = $this->ledger->applyCommission($case, $partner, $booking, $gross, $actor);

        $sequence = ((int) $case->calculations()->max('sequence')) + 1;

        $snapshot = [
            'generated_at' => now()->toIso8601String(),
            'promoter' => [
                'id' => $partner->id,
                'partner_code' => $partner->partner_code,
                'commission_percentage' => $rate,
                'rate_source' => $attribution->commission_percentage !== null
                    ? 'attribution snapshot (frozen at attribution time)'
                    : 'partner live rate (legacy attribution row with no snapshot)',
            ],
            'basis' => [
                'amount' => $basisAmount->store(),
                'source' => 'booking.final_amount (M6 frozen snapshot)',
            ],
            'gross_commission_amount' => $gross->store(),
            'advance_adjusted_amount' => $entry->adjustment_amount,
            'payable_amount' => $entry->payable_amount,
            'ledger_entry_id' => $entry->id,
        ];

        /** @var CommissionCalculation $calc */
        $calc = $case->calculations()->create([
            'sequence' => $sequence,
            'basis_amount' => $basisAmount->store(),
            'commission_amount' => $gross->store(),
            'advance_adjusted_amount' => $entry->adjustment_amount,
            'payable_amount' => $entry->payable_amount,
            'snapshot' => $snapshot,
            'calculated_at' => now(),
            'calculated_by' => $actor->id,
        ]);

        $case->forceFill([
            'partner_id' => $partner->id,
            'current_calculation_id' => $calc->id,
            'commission_amount' => $gross->store(),
            'advance_adjusted_amount' => $entry->adjustment_amount,
            'payable_amount' => $entry->payable_amount,
        ])->save();

        return $calc;
    }
}
