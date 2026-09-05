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
 * Takes a commission case OFF hold (M14.5) — ON_HOLD → PENDING_REVIEW, so it is
 * re-reviewed (and can be recalculated) before approval.
 */
class ResumeCommissionCase
{
    use RunsInTransaction;

    public function handle(CommissionCase $case, User $actor): CommissionCase
    {
        return $this->transaction(function () use ($case, $actor): CommissionCase {
            /** @var CommissionCase $locked */
            $locked = CommissionCase::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== CommissionCaseStatus::OnHold) {
                throw new DomainException("Only a held commission case can be resumed (this one is {$locked->status->label()}).");
            }

            $locked->forceFill([
                'status' => CommissionCaseStatus::PendingReview,
                'held_at' => null,
                'hold_reason' => null,
            ])->save();

            $locked->recordEvent(CommissionCaseEventType::Resumed, 'Resumed — back to pending review.', [], $actor);

            Log::info('commission.case_resumed', ['case_id' => $locked->id, 'by' => $actor->id]);

            return $locked;
        });
    }
}
