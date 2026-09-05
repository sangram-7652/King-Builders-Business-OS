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
use App\Services\Commission\CommissionSchemeResolver;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Sequences\SequenceGenerator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Generates (or refreshes) the commission cases for a CONFIRMED booking (M14.4).
 *
 * One case per partner on the booking's active attribution split. Fully
 * idempotent and guarded against duplicate execution:
 *   - the (booking_id, partner_id) unique index + row locks serialise races
 *   - an existing case that is still recalculable is re-snapshotted in place
 *   - an APPROVED / PAID case is never touched
 *   - a case for a partner no longer in the split is CANCELLED (if not yet approved)
 *   - an ineligible pair records the reason and produces no calculation
 *
 * @return Collection<int, CommissionCase>
 */
class GenerateCommissionCases
{
    use RunsInTransaction;

    public function __construct(
        private readonly SequenceGenerator $sequences,
        private readonly CommissionSchemeResolver $schemes,
        private readonly CommissionEligibilityService $eligibility,
        private readonly CommissionCaseWriter $writer,
    ) {}

    public function handle(Booking $booking, User $actor)
    {
        if (! $booking->isConfirmed()) {
            throw new DomainException('Commission can only be generated for a confirmed booking.');
        }

        return $this->transaction(function () use ($booking, $actor) {
            /** @var Booking $locked */
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            $attributions = $locked->partnerAttributions()->with('partner')->get();
            $activePartnerIds = $attributions->pluck('partner_id')->all();

            // Cancel cases for partners dropped from the split (unless already approved).
            CommissionCase::query()
                ->where('booking_id', $locked->id)
                ->whereNotIn('partner_id', $activePartnerIds ?: [0])
                ->whereIn('status', [CommissionCaseStatus::PendingReview->value, CommissionCaseStatus::OnHold->value])
                ->lockForUpdate()
                ->each(function (CommissionCase $stale) use ($actor): void {
                    $stale->forceFill([
                        'status' => CommissionCaseStatus::Cancelled,
                        'cancelled_at' => now(),
                        'cancellation_reason' => 'Partner removed from the booking attribution split.',
                    ])->save();
                    $stale->recordEvent(CommissionCaseEventType::Cancelled, 'Attribution removed — case cancelled.', [], $actor);
                });

            $cases = collect();

            foreach ($attributions as $attribution) {
                /** @var Partner $partner */
                $partner = $attribution->partner;

                $case = CommissionCase::query()
                    ->where('booking_id', $locked->id)
                    ->where('partner_id', $partner->id)
                    ->lockForUpdate()
                    ->first();

                if ($case !== null && ! $case->status->isRecalculable()) {
                    $cases->push($case);

                    continue; // approved / paid / cancelled — untouched
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

                $eval = $this->eligibility->evaluate($locked, $partner);

                $case->forceFill([
                    'booking_partner_attribution_id' => $attribution->id,
                    'is_eligible' => $eval['eligible'],
                    'eligibility_reason' => $eval['reason'],
                    'eligibility_checked_at' => now(),
                ])->save();

                if ($eval['eligible']) {
                    $resolved = $this->schemes->resolveRule($partner, $locked->project_id);
                    $calc = $this->writer->write($case, $resolved['scheme'], $resolved['rule'], $attribution, $actor);

                    $case->recordEvent(
                        $isNew ? CommissionCaseEventType::Generated : CommissionCaseEventType::Recalculated,
                        ($isNew ? 'Generated' : 'Recalculated')." — {$resolved['scheme']->code} v{$resolved['scheme']->version}, ₹{$calc->commission_amount}.",
                        ['calculation_id' => $calc->id, 'sequence' => $calc->sequence],
                        $actor,
                    );
                } elseif ($isNew) {
                    $case->recordEvent(CommissionCaseEventType::Generated, "Not eligible — {$eval['reason']}", [], $actor);
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
