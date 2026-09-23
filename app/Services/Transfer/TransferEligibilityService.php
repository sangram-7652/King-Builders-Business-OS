<?php

declare(strict_types=1);

namespace App\Services\Transfer;

use App\Enums\BookingStatus;
use App\Enums\DocumentStatus;
use App\Enums\PlotStatus;
use App\Enums\TransferRequestStatus;
use App\Models\Booking;
use App\Models\Document;
use App\Models\Masters\DocumentType;
use App\Models\Plot;
use App\Models\TransferRequest;
use App\Services\Payments\PaymentLedger;
use App\Support\Money;
use App\Support\Registry\EligibilityResult;

/**
 * Decides whether a transfer request may be APPROVED (M10). Consumes M6/M7/M8/M9
 * state; never recomputes a balance and never mutates anything.
 *
 * Ownership-moving / nominee types:
 *   - booking is CONFIRMED
 *   - there is no OTHER active transfer on the same booking
 *   - financial clearance: outstanding within config, unless the reviewer
 *     recorded an explicit `financial_waiver_reason`
 *   - required transfer documents are VERIFIED (for ownership-moving types)
 *
 * Plot transfer (buyer unchanged, plot changes):
 *   - booking is CONFIRMED and still sits on the plot the request was raised for
 *   - there is no OTHER active transfer on the same booking, or on the target plot
 *   - the target plot is in the same project, still active, AVAILABLE, and
 *     has no live booking
 *   - Registry / Possession status are deliberately NOT checked — a plot
 *     transfer is allowed even when both are DONE (client requirement); the
 *     statuses carry over to the new plot unchanged (see PlotTransferService)
 *   - financial clearance and transfer-document checks never apply
 */
class TransferEligibilityService
{
    public function __construct(private readonly PaymentLedger $ledger) {}

    public function evaluate(TransferRequest $transfer): EligibilityResult
    {
        $booking = $transfer->relationLoaded('booking') ? $transfer->booking : $transfer->booking()->first();

        if ($transfer->transfer_type->movesPlot()) {
            return $this->evaluatePlotTransfer($transfer, $booking);
        }

        $cfg = config('transfer');
        $checks = [];

        $confirmed = $booking !== null && $booking->status === BookingStatus::Confirmed;
        $checks[] = $this->check('booking_confirmed', 'Booking confirmed', $confirmed,
            $confirmed ? null : 'Booking is not confirmed.');

        if ($transfer->transfer_type->movesOwnership()) {
            $newBuyerOk = $transfer->new_buyer_id !== null && $transfer->new_buyer_id !== $transfer->current_buyer_id;
            $checks[] = $this->check('new_buyer', 'A distinct incoming buyer is set', $newBuyerOk,
                $newBuyerOk ? null : 'The incoming buyer is missing or the same as the current owner.');
        }

        $conflict = TransferRequest::query()
            ->where('booking_id', $transfer->booking_id)
            ->whereKeyNot($transfer->getKey())
            ->whereIn('status', [
                TransferRequestStatus::Submitted->value,
                TransferRequestStatus::UnderReview->value,
                TransferRequestStatus::DocumentsPending->value,
                TransferRequestStatus::Approved->value,
            ])
            ->exists();
        $checks[] = $this->check('no_conflicting_transfer', 'No other active transfer on this booking', ! $conflict,
            $conflict ? 'Another transfer request on this booking is still open.' : null);

        // --- Financial ------------------------------------------------
        if ($booking !== null) {
            $waived = trim((string) $transfer->financial_waiver_reason) !== '';
            $outstanding = $this->ledger->bookingOutstanding($booking);
            $max = Money::of($cfg['financial']['max_outstanding']);

            $financialOk = $waived
                || ! $cfg['financial']['block_on_outstanding']
                || ! $outstanding->greaterThan($max);
            $checks[] = $this->check('financial_clearance', 'Financial clearance', $financialOk,
                $financialOk ? null : "Outstanding ₹{$outstanding->store()} (no waiver).");
        }

        // --- Documents (ownership-moving transfers only) --------------
        if ($transfer->transfer_type->movesOwnership() && $cfg['required_document_codes'] !== []) {
            $typeIds = DocumentType::query()->whereIn('code', $cfg['required_document_codes'])->pluck('id', 'code');
            $verified = Document::query()
                ->where('documentable_type', (new Booking)->getMorphClass())
                ->where('documentable_id', $transfer->booking_id)
                ->whereIn('document_type_id', $typeIds->values())
                ->where('status', DocumentStatus::Verified->value)
                ->count();
            $docsOk = $verified >= count($cfg['required_document_codes']);
            $checks[] = $this->check('documents_verified', 'Transfer documents verified', $docsOk,
                $docsOk ? null : "{$verified}/".count($cfg['required_document_codes']).' transfer documents verified.');
        }

        $eligible = ! in_array(false, array_column($checks, 'passed'), true);

        return new EligibilityResult(eligible: $eligible, checks: $checks);
    }

    private function evaluatePlotTransfer(TransferRequest $transfer, ?Booking $booking): EligibilityResult
    {
        $checks = [];

        $confirmed = $booking !== null && $booking->status === BookingStatus::Confirmed;
        $checks[] = $this->check('booking_confirmed', 'Booking confirmed', $confirmed,
            $confirmed ? null : 'Booking is not confirmed.');

        $onOriginalPlot = $booking !== null && $booking->plot_id === $transfer->plot_id;
        $checks[] = $this->check('current_plot_unchanged', 'Booking still sits on the plot this request was raised for', $onOriginalPlot,
            $onOriginalPlot ? null : 'The booking\'s plot has changed since this request was raised.');

        $conflict = TransferRequest::query()
            ->where('booking_id', $transfer->booking_id)
            ->whereKeyNot($transfer->getKey())
            ->whereIn('status', [
                TransferRequestStatus::Submitted->value,
                TransferRequestStatus::UnderReview->value,
                TransferRequestStatus::DocumentsPending->value,
                TransferRequestStatus::Approved->value,
            ])
            ->exists();
        $checks[] = $this->check('no_conflicting_transfer', 'No other active transfer on this booking', ! $conflict,
            $conflict ? 'Another transfer request on this booking is still open.' : null);

        $newPlot = $transfer->new_plot_id !== null ? Plot::query()->find($transfer->new_plot_id) : null;

        if ($newPlot === null) {
            $checks[] = $this->check('target_plot_set', 'A target plot is set', false, 'No target plot is set on this request.');
        } else {
            $sameProject = $booking !== null && $newPlot->project_id === $booking->project_id;
            $checks[] = $this->check('target_plot_same_project', 'Target plot belongs to the same project', $sameProject,
                $sameProject ? null : 'The target plot must belong to the same project as the current booking.');

            $targetConflict = TransferRequest::query()
                ->where('new_plot_id', $newPlot->id)
                ->whereKeyNot($transfer->getKey())
                ->whereIn('status', [
                    TransferRequestStatus::Submitted->value,
                    TransferRequestStatus::UnderReview->value,
                    TransferRequestStatus::DocumentsPending->value,
                    TransferRequestStatus::Approved->value,
                ])
                ->exists();
            $checks[] = $this->check('no_conflicting_target', 'No other open transfer already targets this plot', ! $targetConflict,
                $targetConflict ? 'Another open transfer already targets this plot.' : null);

            $available = $newPlot->is_active && $newPlot->status === PlotStatus::Available;
            $checks[] = $this->check('target_plot_available', 'Target plot is available', $available,
                $available ? null : "Target plot is {$newPlot->status->label()}.");

            $liveBooking = Booking::query()
                ->where('plot_id', $newPlot->id)
                ->whereIn('status', [BookingStatus::Pending->value, BookingStatus::Confirmed->value])
                ->exists();
            $checks[] = $this->check('target_plot_no_live_booking', 'Target plot has no live booking', ! $liveBooking,
                $liveBooking ? 'Target plot already has a live booking.' : null);
        }

        $eligible = ! in_array(false, array_column($checks, 'passed'), true);

        return new EligibilityResult(eligible: $eligible, checks: $checks);
    }

    /**
     * @return array{key: string, label: string, passed: bool, detail: string|null}
     */
    private function check(string $key, string $label, bool $passed, ?string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'passed' => $passed, 'detail' => $detail];
    }
}
