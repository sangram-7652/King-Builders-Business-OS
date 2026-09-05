<?php

declare(strict_types=1);

namespace App\Actions\Commission;

use App\Enums\CommissionCaseEventType;
use App\Enums\CommissionCaseStatus;
use App\Exceptions\DomainException;
use App\Models\CommissionCase;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Reverses an approved / paid commission case (M14.5) — APPROVED /
 * PARTIALLY_PAID / PAID → REVERSED. Used when a booking is cancelled or the
 * attribution is corrected after the commission was committed.
 *
 * `clawback_amount` is set to whatever was already paid out — the amount to be
 * recovered from the partner operationally. Recorded payouts are left as the
 * historical record (not voided); the partner statement (M14.6) nets the
 * clawback. This is NOT an accounting reversal.
 */
class ReverseCommissionCase
{
    use RunsInTransaction;

    public function handle(CommissionCase $case, User $actor, string $reason): CommissionCase
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('A reversal needs a reason.');
        }

        return $this->transaction(function () use ($case, $actor, $reason): CommissionCase {
            /** @var CommissionCase $locked */
            $locked = CommissionCase::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === CommissionCaseStatus::Reversed) {
                return $locked;
            }

            if (! $locked->status->canTransitionTo(CommissionCaseStatus::Reversed)) {
                throw new DomainException("A {$locked->status->label()} commission case cannot be reversed.");
            }

            $clawback = bcadd((string) $locked->paid_amount, '0', 2);

            $locked->forceFill([
                'status' => CommissionCaseStatus::Reversed,
                'reversed_at' => now(),
                'reversal_reason' => $reason,
                'clawback_amount' => $clawback,
            ])->save();

            $locked->recordEvent(
                CommissionCaseEventType::Reversed,
                bccomp($clawback, '0', 2) > 0
                    ? "Reversed — {$reason}. Clawback of ₹{$clawback} to recover."
                    : "Reversed — {$reason}. Nothing paid out.",
                ['clawback_amount' => $clawback],
                $actor,
            );

            Log::info('commission.case_reversed', [
                'case_id' => $locked->id, 'clawback' => $clawback, 'by' => $actor->id,
            ]);

            return $locked;
        });
    }
}
