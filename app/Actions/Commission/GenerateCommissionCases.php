<?php

declare(strict_types=1);

namespace App\Actions\Commission;

use App\Enums\CommissionCaseEventType;
use App\Enums\CommissionCaseStatus;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\CommissionCase;
use App\Models\Partner;
use App\Models\User;
use App\Services\Commission\CommissionCaseWriter;
use App\Services\Commission\CommissionEligibilityService;
use App\Services\Commission\PromoterLedgerService;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Sequences\SequenceGenerator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Generates (or refreshes) the commission case for a CONFIRMED booking's
 * promoter (M14.4). One promoter maximum per booking, so at most one case.
 *
 * Fully idempotent and guarded against duplicate execution:
 *   - the (booking_id, partner_id) unique index + row locks serialise races
 *   - an existing case that is still recalculable is re-snapshotted in place
 *     (its advance adjustment is reversed and reapplied against the fresh
 *     figure — see {@see PromoterLedgerService::applyCommission()} — so
 *     repeated calls never double-consume the promoter's advance)
 *   - an APPROVED / PAID case is never touched
 *   - a case for a promoter no longer attributed to the booking is CANCELLED
 *     (if not yet approved), and its advance adjustment compensated
 *   - an ineligible promoter records the reason and produces no calculation
 *
 * @return Collection<int, CommissionCase>
 */
class GenerateCommissionCases
{
    use RunsInTransaction;

    public function __construct(
        private readonly SequenceGenerator $sequences,
        private readonly CommissionEligibilityService $eligibility,
        private readonly CommissionCaseWriter $writer,
        private readonly PromoterLedgerService $ledger,
    ) {}

    public function handle(Booking $booking, User $actor)
    {
        if (! $booking->isConfirmed()) {
            throw new DomainException('Commission can only be generated for a confirmed booking.');
        }

        return $this->transaction(function () use ($booking, $actor) {
            /** @var Booking $locked */
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            $attribution = $locked->partnerAttributions()->with('partner')->first();
            $activePartnerId = $attribution?->partner_id;

            // Cancel a case for a promoter no longer attributed to this booking.
            CommissionCase::query()
                ->where('booking_id', $locked->id)
                ->when($activePartnerId !== null, fn ($q) => $q->where('partner_id', '!=', $activePartnerId))
                ->whereIn('status', [CommissionCaseStatus::PendingReview->value, CommissionCaseStatus::OnHold->value])
                ->lockForUpdate()
                ->each(function (CommissionCase $stale) use ($actor): void {
                    $this->ledger->reverseCaseAdjustment($stale, $actor, 'Promoter removed from the booking — commission cancelled.');

                    $stale->forceFill([
                        'status' => CommissionCaseStatus::Cancelled,
                        'cancelled_at' => now(),
                        'cancellation_reason' => 'Promoter removed from the booking.',
                    ])->save();
                    $stale->recordEvent(CommissionCaseEventType::Cancelled, 'Promoter attribution removed — case cancelled.', [], $actor);
                });

            $cases = collect();

            if ($attribution !== null) {
                /** @var Partner $partner */
                $partner = $attribution->partner;

                $case = CommissionCase::query()
                    ->where('booking_id', $locked->id)
                    ->where('partner_id', $partner->id)
                    ->lockForUpdate()
                    ->first();

                if ($case !== null && ! $case->status->isRecalculable()) {
                    $cases->push($case);

                    return $cases;
                }

                $isNew = $case === null;

                if ($isNew) {
                    $case = CommissionCase::create([
                        'case_number' => CommissionCase::formatCode($this->sequences->next(CommissionCase::SEQUENCE_KEY)),
                        'booking_id' => $locked->id,
                        'partner_id' => $partner->id,
                        'status' => CommissionCaseStatus::PendingReview,
                        'generated_at' => now(),
                        'generated_by' => $actor->id,
                    ]);
                }

                $rate = (string) ($attribution->commission_percentage ?? $partner->commission_percentage ?? '');
                $eval = $this->eligibility->evaluate($locked, $partner, $rate !== '' ? $rate : null);

                $case->forceFill([
                    'booking_partner_attribution_id' => $attribution->id,
                    'is_eligible' => $eval['eligible'],
                    'eligibility_reason' => $eval['reason'],
                    'eligibility_checked_at' => now(),
                ])->save();

                if ($eval['eligible']) {
                    $calc = $this->writer->write($case, $partner, $locked, $attribution, $actor);

                    $case->recordEvent(
                        $isNew ? CommissionCaseEventType::Generated : CommissionCaseEventType::Recalculated,
                        ($isNew ? 'Generated' : 'Recalculated')." — gross ₹{$calc->commission_amount}, payable ₹{$calc->payable_amount}.",
                        ['calculation_id' => $calc->id, 'sequence' => $calc->sequence],
                        $actor,
                    );
                } elseif ($isNew) {
                    $case->recordEvent(CommissionCaseEventType::Generated, "Not eligible — {$eval['reason']}", [], $actor);
                } else {
                    // Went from eligible to ineligible on recalculation — compensate any advance it had consumed.
                    $this->ledger->reverseCaseAdjustment($case, $actor, "No longer eligible — {$eval['reason']}");
                }

                $cases->push($case->fresh());
            }

            Log::info('commission.cases_generated', [
                'booking_id' => $locked->id,
                'count' => $cases->count(),
                'by' => $actor->id,
            ]);

            return $cases;
        });
    }
}
