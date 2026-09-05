<?php

declare(strict_types=1);

namespace App\Actions\Commission;

use App\Actions\Commission\Concerns\SyncsCommissionPayment;
use App\Enums\CommissionCaseEventType;
use App\Enums\CommissionPayoutMethod;
use App\Exceptions\DomainException;
use App\Models\CommissionCase;
use App\Models\CommissionPayout;
use App\Models\User;
use App\Services\Commission\CommissionEligibilityService;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Money;
use Illuminate\Support\Facades\Log;

/**
 * Records a commission payout against an APPROVED / PARTIALLY_PAID case (M14.5).
 * Operational tracking only — no ledger entry, no GST/TDS.
 *
 *   - amount must be > 0
 *   - Σ(recorded payouts) + amount must not exceed the case commission amount
 *   - reaching the full amount flips the case to PAID
 *
 * Row-locked so two concurrent payouts cannot together overpay.
 */
class RecordCommissionPayout
{
    use RunsInTransaction;
    use SyncsCommissionPayment;

    public function __construct(private readonly CommissionEligibilityService $eligibility) {}

    /**
     * @param  array{amount: string|int|float, method: string, paid_on: string, reference?: string|null, notes?: string|null}  $data
     */
    public function handle(CommissionCase $case, array $data, User $actor): CommissionPayout
    {
        return $this->transaction(function () use ($case, $data, $actor): CommissionPayout {
            /** @var CommissionCase $locked */
            $locked = CommissionCase::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->isPayable()) {
                throw new DomainException("A {$locked->status->label()} commission case cannot receive a payout.");
            }

            // F-M14-3: the configured minimum-collected gate is re-checked here
            // against CURRENT M7 truth — a reversed payment or bounced cheque
            // since the case was generated can pull the booking back below it.
            $locked->loadMissing('booking');
            if ($locked->booking !== null
                && ($shortfall = $this->eligibility->collectionShortfallReason($locked->booking)) !== null
            ) {
                throw new DomainException("Commission payout blocked — {$shortfall}");
            }

            $amount = Money::of((string) $data['amount']);
            if (! $amount->isPositive()) {
                throw new DomainException('A payout amount must be greater than zero.');
            }

            $alreadyPaid = Money::of((string) $locked->recordedPayouts()->sum('amount'));
            $total = Money::of($locked->commission_amount);

            if ($alreadyPaid->plus($amount)->greaterThan($total)) {
                $remaining = $total->minus($alreadyPaid);
                throw new DomainException("That exceeds the commission — only ₹{$remaining->store()} is still payable.");
            }

            /** @var CommissionPayout $payout */
            $payout = $locked->payouts()->create([
                'amount' => $amount->store(),
                'method' => CommissionPayoutMethod::from($data['method']),
                'paid_on' => $data['paid_on'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $actor->id,
            ]);

            $this->syncPaidAmount($locked, $actor);

            $locked->recordEvent(
                CommissionCaseEventType::PayoutRecorded,
                "Payout ₹{$payout->amount} ({$payout->method->label()}) recorded.",
                ['payout_id' => $payout->id],
                $actor,
            );

            Log::info('commission.payout_recorded', [
                'case_id' => $locked->id, 'payout_id' => $payout->id, 'amount' => $payout->amount, 'by' => $actor->id,
            ]);

            return $payout;
        });
    }
}
