<?php

declare(strict_types=1);

namespace App\Actions\Commission;

use App\Enums\CommissionCaseEventType;
use App\Exceptions\DomainException;
use App\Models\CommissionCase;
use App\Models\User;
use App\Services\Commission\CommissionCaseWriter;
use App\Services\Commission\CommissionEligibilityService;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Recomputes a single commission case against the booking's live final_amount
 * (M14.4) — but NOT against the promoter's live commission rate, which stays
 * pinned to whatever was snapshotted onto the attribution when the promoter
 * was attached to this booking (see CommissionCaseWriter). Only a
 * PENDING_REVIEW / ON_HOLD case can be recalculated — once approved or paid
 * the snapshot is locked. A new immutable calculation row is appended; the
 * previous ones stay untouched. The promoter's advance adjustment is reversed
 * and reapplied against the fresh gross figure (never double-consumed — see
 * {@see \App\Services\Commission\PromoterLedgerService::applyCommission()}).
 */
class RecalculateCommissionCase
{
    use RunsInTransaction;

    public function __construct(
        private readonly CommissionEligibilityService $eligibility,
        private readonly CommissionCaseWriter $writer,
    ) {}

    public function handle(CommissionCase $case, User $actor): CommissionCase
    {
        return $this->transaction(function () use ($case, $actor): CommissionCase {
            /** @var CommissionCase $locked */
            $locked = CommissionCase::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->isRecalculable()) {
                throw new DomainException("A {$locked->status->label()} commission case cannot be recalculated.");
            }

            $locked->load('booking', 'partner', 'attribution');

            $attribution = $locked->attribution;
            if ($attribution === null || ! $attribution->isActive()) {
                throw new DomainException('The booking attribution this case was generated from is no longer active. Regenerate from the booking.');
            }

            $rate = (string) ($attribution->commission_percentage ?? $locked->partner->commission_percentage ?? '');
            $eval = $this->eligibility->evaluate($locked->booking, $locked->partner, $rate !== '' ? $rate : null);

            $locked->forceFill([
                'is_eligible' => $eval['eligible'],
                'eligibility_reason' => $eval['reason'],
                'eligibility_checked_at' => now(),
            ])->save();

            if (! $eval['eligible']) {
                throw new DomainException("Not eligible — {$eval['reason']}");
            }

            $calc = $this->writer->write($locked, $locked->partner, $locked->booking, $attribution, $actor);

            $locked->recordEvent(
                CommissionCaseEventType::Recalculated,
                "Recalculated — gross ₹{$calc->commission_amount}, payable ₹{$calc->payable_amount}.",
                ['calculation_id' => $calc->id, 'sequence' => $calc->sequence],
                $actor,
            );

            Log::info('commission.case_recalculated', [
                'case_id' => $locked->id, 'sequence' => $calc->sequence, 'by' => $actor->id,
            ]);

            return $locked->fresh();
        });
    }
}
