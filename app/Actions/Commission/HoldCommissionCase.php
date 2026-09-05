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
 * Puts a commission case ON_HOLD (M14.5) — PENDING_REVIEW / APPROVED → ON_HOLD.
 * A held case cannot be paid; resume it to continue.
 */
class HoldCommissionCase
{
    use RunsInTransaction;

    public function handle(CommissionCase $case, User $actor, string $reason): CommissionCase
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('A hold needs a reason.');
        }

        return $this->transaction(function () use ($case, $actor, $reason): CommissionCase {
            /** @var CommissionCase $locked */
            $locked = CommissionCase::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === CommissionCaseStatus::OnHold) {
                return $locked;
            }

            if (! $locked->status->canTransitionTo(CommissionCaseStatus::OnHold)) {
                throw new DomainException("A {$locked->status->label()} commission case cannot be put on hold.");
            }

            $locked->forceFill([
                'status' => CommissionCaseStatus::OnHold,
                'held_at' => now(),
                'hold_reason' => $reason,
            ])->save();

            $locked->recordEvent(CommissionCaseEventType::PutOnHold, "On hold — {$reason}", [], $actor);

            Log::info('commission.case_held', ['case_id' => $locked->id, 'by' => $actor->id]);

            return $locked;
        });
    }
}
