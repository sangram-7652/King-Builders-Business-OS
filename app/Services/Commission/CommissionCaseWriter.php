<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\Booking;
use App\Models\CommissionCalculation;
use App\Models\CommissionCase;
use App\Models\Partner;
use App\Models\User;
use App\Support\Money;

/**
 * Computes a promoter's flat commission — Booking final_amount × Partner
 * commission_percentage — applies it against the promoter's advance via
 * {@see PromoterLedgerService}, and appends one immutable
 * {@see CommissionCalculation} row to the case (M14.4). Never mutates an
 * existing calculation row — recalculating writes a new sequence.
 *
 * Must be called inside the caller's transaction.
 */
class CommissionCaseWriter
{
    public function __construct(private readonly PromoterLedgerService $ledger) {}

    public function write(CommissionCase $case, Partner $partner, Booking $booking, User $actor): CommissionCalculation
    {
        $basisAmount = Money::of($booking->final_amount);
        $rate = (string) ($partner->commission_percentage ?? '0');
        $gross = Money::of($rate)->percentageOf($basisAmount);

        $entry = $this->ledger->applyCommission($case, $partner, $booking, $gross, $actor);

        $sequence = ((int) $case->calculations()->max('sequence')) + 1;

        $snapshot = [
            'generated_at' => now()->toIso8601String(),
            'promoter' => [
                'id' => $partner->id,
                'partner_code' => $partner->partner_code,
                'commission_percentage' => $rate,
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
