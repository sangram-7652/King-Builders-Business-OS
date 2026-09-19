<?php

declare(strict_types=1);

namespace App\Actions\Possession;

use App\Enums\ClearanceCategory;
use App\Enums\ClearanceStatus;
use App\Enums\PossessionActivityType;
use App\Exceptions\DomainException;
use App\Models\PossessionCase;
use App\Models\PossessionClearance;
use App\Models\User;
use App\Services\Payments\PaymentLedger;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Money;
use App\Support\Possession\PossessionTimeline;
use Illuminate\Support\Facades\Log;

/**
 * Records a possession clearance decision (M10). PENDING → CLEARED / REJECTED /
 * WAIVED. `possession.clear`.
 *
 *   - nothing is ever waived automatically — WAIVED needs a reason
 *   - FINANCIAL clearance reads M7/M8 truth: it cannot be marked CLEARED while
 *     the booking still owes more than the configured threshold (use WAIVED for
 *     an explicit exception)
 */
class RecordClearanceAction
{
    use RunsInTransaction;

    public function __construct(private readonly PaymentLedger $ledger) {}

    /**
     * @param  array{remarks?: string|null, waiver_reason?: string|null}  $data
     */
    public function handle(PossessionCase $case, ClearanceCategory $category, ClearanceStatus $target, User $actor, array $data = []): PossessionClearance
    {
        if (! $actor->can('possession.clear')) {
            throw new DomainException('You are not authorised to record possession clearances.');
        }

        if ($target === ClearanceStatus::Pending) {
            throw new DomainException('A clearance decision must be CLEARED, REJECTED or WAIVED.');
        }

        if ($target === ClearanceStatus::Waived && trim((string) ($data['waiver_reason'] ?? '')) === '') {
            throw new DomainException('A waiver reason is required to waive a clearance.');
        }

        return $this->transaction(function () use ($case, $category, $target, $actor, $data): PossessionClearance {
            /** @var PossessionClearance $clearance */
            $clearance = PossessionClearance::query()
                ->where('possession_case_id', $case->getKey())
                ->where('category', $category->value)
                ->lockForUpdate()
                ->firstOrFail();

            if ($clearance->status === $target) {
                return $clearance;
            }

            $snapshot = null;

            if ($category === ClearanceCategory::Financial) {
                $case->loadMissing('booking');
                $outstanding = $this->ledger->bookingOutstanding($case->booking);
                $snapshot = ['outstanding' => $outstanding->store()];

                if ($target === ClearanceStatus::Cleared) {
                    $max = Money::of(config('possession.financial_clearance.max_outstanding'));
                    if ($outstanding->greaterThan($max)) {
                        throw new DomainException("Financial clearance cannot be given: ₹{$outstanding->store()} is still outstanding. Waive it explicitly if this is an approved exception.");
                    }
                }
            }

            $clearance->forceFill([
                'status' => $target,
                'remarks' => $data['remarks'] ?? $clearance->remarks,
                'waiver_reason' => $target === ClearanceStatus::Waived ? trim((string) $data['waiver_reason']) : null,
                'snapshot' => $snapshot,
                'decided_by' => $actor->id,
                'decided_at' => now(),
            ])->save();

            PossessionTimeline::record(
                PossessionActivityType::ClearanceChanged,
                "{$category->label()} clearance {$target->label()} on {$case->case_number}.",
                $case->booking, $case->booking?->plot, null,
                ['possession_case_id' => $case->id, 'category' => $category->value, 'status' => $target->value],
                $actor,
            );

            Log::info('possession_clearance.recorded', [
                'possession_case_id' => $case->id, 'category' => $category->value, 'status' => $target->value, 'by' => $actor->id,
            ]);

            return $clearance;
        });
    }
}
