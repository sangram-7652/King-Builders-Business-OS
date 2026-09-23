<?php

declare(strict_types=1);

namespace App\Actions\Transfer;

use App\Enums\Permission;
use App\Enums\PossessionActivityType;
use App\Enums\TransferRequestStatus;
use App\Enums\TransferType;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\Plot;
use App\Models\TransferRequest;
use App\Models\User;
use App\Services\Transfer\PlotTransferService;
use App\Services\Transfer\TransferEligibilityService;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Possession\PossessionTimeline;
use App\Support\Sequences\SequenceGenerator;
use Illuminate\Support\Facades\Log;

/**
 * The ONLY transfer workflow the product now exposes: move a confirmed
 * booking from its current plot to a different, available plot in the same
 * project. The buyer, booking number, pricing snapshot, payments, documents,
 * promoter/commission, Registry status and Possession status are never
 * touched — only which plot the booking sits on changes. A transfer is
 * allowed even when Registry and/or Possession are DONE: the old plot is
 * released to AVAILABLE and the new plot takes the booking's inventory state
 * (SOLD if Registry is DONE, otherwise BOOKED) — see PlotTransferService.
 *
 * This is a single, atomic, one-step action — there is no Draft / Submitted /
 * UnderReview / Approved ceremony for a plot transfer: every one of
 * {@see TransferEligibilityService::evaluate()}'s plot-transfer checks is
 * purely structural (no human judgement, no financial waiver, no document
 * review), so a plot transfer goes straight from nothing to COMPLETED, same
 * as the client's requested UX ("Transfer Plot" → done).
 *
 * The OLD multi-step ownership/nominee transfer workflow
 * ({@see CreateTransferRequestAction}, {@see TransferWorkflowAction}) is
 * completely untouched by this class and remains fully intact for any
 * historical request — this is an ADDITIONAL, simpler entry point into the
 * SAME `transfer_requests` table (reusing it as the transfer-history store,
 * per the product requirement), not a replacement for it.
 *
 * Reuses the exact same core mechanism as the old workflow's
 * {@see CompleteTransferAction}: {@see TransferEligibilityService} for
 * validation and {@see PlotTransferService::applyPlotChange()} for the
 * actual plot swap — so both entry points share one, single-source-of-truth
 * implementation of "what makes a plot transfer valid" and "what a plot
 * transfer actually does".
 */
class ExecutePlotTransferAction
{
    use RunsInTransaction;

    public function __construct(
        private readonly SequenceGenerator $sequences,
        private readonly PlotTransferService $plotTransfer,
        private readonly TransferEligibilityService $eligibility,
    ) {}

    public function handle(Booking $booking, int $newPlotId, ?string $reason, User $actor): TransferRequest
    {
        if (! $actor->can(Permission::TransferComplete->value)) {
            throw new DomainException('You are not authorised to transfer a plot.');
        }

        return $this->transaction(function () use ($booking, $newPlotId, $reason, $actor): TransferRequest {
            /** @var Booking $lockedBooking */
            $lockedBooking = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            if (! $lockedBooking->isConfirmed()) {
                throw new DomainException('A plot can only be transferred for a confirmed booking.');
            }

            // Lock both plots in ONE statement, in a STABLE id order, so two
            // simultaneous transfers touching the same pair of plots (in
            // either direction) can never deadlock against each other.
            $plots = Plot::query()
                ->whereKey(array_unique([$lockedBooking->plot_id, $newPlotId]))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var Plot $oldPlot */
            $oldPlot = $plots->get($lockedBooking->plot_id) ?? throw new DomainException('The booking\'s current plot no longer exists.');
            $newPlot = $plots->get($newPlotId);

            if ($newPlot === null) {
                throw new DomainException('The selected plot no longer exists.');
            }

            $transfer = TransferRequest::create([
                'request_number' => TransferRequest::formatCode($this->sequences->next(TransferRequest::SEQUENCE_KEY)),
                'booking_id' => $lockedBooking->id,
                'plot_id' => $oldPlot->id,
                'new_plot_id' => $newPlot->id,
                'transfer_type' => TransferType::PlotTransfer,
                'status' => TransferRequestStatus::Completed,
                'reason' => $reason,
                'requested_at' => now(),
                'requested_by' => $actor->id,
                'approved_at' => now(),
                'approved_by' => $actor->id,
                'completed_at' => now(),
                'completed_by' => $actor->id,
                'created_by' => $actor->id,
            ]);
            $transfer->setRelation('booking', $lockedBooking);

            $result = $this->eligibility->evaluate($transfer);

            if (! $result->eligible) {
                throw new DomainException('Plot transfer is not eligible: '.implode(' ', $result->reasons()));
            }

            $transfer->forceFill(['financial_snapshot' => $result->toArray()])->save();

            $this->plotTransfer->applyPlotChange($transfer, $oldPlot, $newPlot, $actor);

            PossessionTimeline::record(
                PossessionActivityType::TransferCompleted,
                "Transfer {$transfer->request_number} completed.",
                $lockedBooking, $newPlot, null,
                ['transfer_request_id' => $transfer->id, 'old_plot_id' => $oldPlot->id, 'new_plot_id' => $newPlot->id],
                $actor,
            );

            Log::info('plot_transfer.completed', [
                'transfer_request_id' => $transfer->id,
                'booking_id' => $lockedBooking->id,
                'old_plot_id' => $oldPlot->id,
                'new_plot_id' => $newPlot->id,
                'by' => $actor->id,
            ]);

            return $transfer->fresh(['plot', 'newPlot']);
        });
    }
}
