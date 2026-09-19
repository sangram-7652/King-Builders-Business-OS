<?php

declare(strict_types=1);

namespace App\Actions\Commission;

use App\Actions\Commission\Concerns\SyncsCommissionPayment;
use App\Enums\CommissionCaseEventType;
use App\Enums\CommissionCaseStatus;
use App\Enums\CommissionPayoutStatus;
use App\Exceptions\DomainException;
use App\Models\CommissionPayout;
use App\Models\User;
use App\Services\Commission\PromoterLedgerService;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Voids a recorded commission payout (M14.5) — kept for the audit trail, not
 * deleted. The case's `paid_amount` is recomputed and its status steps back
 * (PAID → PARTIALLY_PAID / APPROVED) as needed. A reversed case's payouts are
 * frozen.
 */
class VoidCommissionPayout
{
    use RunsInTransaction;
    use SyncsCommissionPayment;

    public function __construct(private readonly PromoterLedgerService $ledger) {}

    public function handle(CommissionPayout $payout, User $actor, string $reason): CommissionPayout
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Voiding a payout needs a reason.');
        }

        return $this->transaction(function () use ($payout, $actor, $reason): CommissionPayout {
            /** @var CommissionPayout $locked */
            $locked = CommissionPayout::query()->whereKey($payout->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === CommissionPayoutStatus::Voided) {
                return $locked;
            }

            $case = $locked->commissionCase()->lockForUpdate()->firstOrFail();

            if ($case->status === CommissionCaseStatus::Reversed) {
                throw new DomainException('This commission case has been reversed — its payouts are frozen.');
            }

            $locked->forceFill([
                'status' => CommissionPayoutStatus::Voided,
                'voided_at' => now(),
                'voided_by' => $actor->id,
                'void_reason' => $reason,
            ])->save();

            $this->syncPaidAmount($case, $actor);
            $this->ledger->mirrorPayoutVoided($case, $locked, $actor);

            $case->recordEvent(
                CommissionCaseEventType::PayoutVoided,
                "Payout ₹{$locked->amount} voided — {$reason}",
                ['payout_id' => $locked->id],
                $actor,
            );

            Log::info('commission.payout_voided', ['case_id' => $case->id, 'payout_id' => $locked->id, 'by' => $actor->id]);

            return $locked;
        });
    }
}
