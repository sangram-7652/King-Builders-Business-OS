<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Actions\Payments\Concerns\RecalculatesLedger;
use App\Enums\InstallmentStatus;
use App\Exceptions\DomainException;
use App\Models\Installment;
use App\Models\User;
use App\Services\Payments\PaymentLedger;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Explicitly waives an installment (M7) — an authorised decision, not a derived
 * state. A waived installment counts as settled and owes nothing; it cannot be
 * waived once any money has been allocated to it.
 */
class WaiveInstallmentAction
{
    use RecalculatesLedger;
    use RunsInTransaction;

    public function __construct(private readonly PaymentLedger $ledger) {}

    public function handle(Installment $installment, User $actor, string $reason, bool $unwaive = false): Installment
    {
        if (trim($reason) === '' && ! $unwaive) {
            throw new DomainException('Waiving an installment needs a reason.');
        }

        return $this->transaction(function () use ($installment, $actor, $reason, $unwaive): Installment {
            /** @var Installment $locked */
            $locked = Installment::query()->whereKey($installment->getKey())->lockForUpdate()->firstOrFail();
            $locked->load('paymentPlan');

            if ($unwaive) {
                $locked->forceFill(['waived_at' => null, 'waived_by' => null, 'waiver_reason' => null])->save();
                $locked->status = $this->ledger->deriveInstallmentStatus($locked->fresh());
                $locked->save();
                $this->recalculatePlan($locked->paymentPlan);

                return $locked;
            }

            if ($locked->status === InstallmentStatus::Waived) {
                return $locked;
            }

            if ($this->ledger->installmentPaid($locked)->isPositive()) {
                throw new DomainException('An installment with money already allocated to it cannot be waived.');
            }

            $locked->forceFill([
                'status' => InstallmentStatus::Waived,
                'waived_at' => now(),
                'waived_by' => $actor->id,
                'waiver_reason' => trim($reason),
            ])->save();

            $this->recalculatePlan($locked->paymentPlan);

            Log::info('installment.waived', [
                'installment_id' => $locked->id,
                'payment_plan_id' => $locked->payment_plan_id,
                'by' => $actor->id,
            ]);

            return $locked;
        });
    }
}
