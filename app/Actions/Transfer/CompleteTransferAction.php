<?php

declare(strict_types=1);

namespace App\Actions\Transfer;

use App\Enums\PossessionActivityType;
use App\Enums\TransferRequestStatus;
use App\Exceptions\DomainException;
use App\Models\Plot;
use App\Models\TransferRequest;
use App\Models\User;
use App\Services\Ownership\PlotOwnershipService;
use App\Services\Transfer\PlotTransferService;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Possession\PossessionTimeline;
use Illuminate\Support\Facades\Log;

/**
 * Completes an APPROVED transfer request (M10). `transfer.complete`.
 *
 * Fully transactional:
 *   1. lock the request; must be APPROVED (idempotent if already COMPLETED)
 *   2. lock the plot(s) — both old and new for a plot transfer; reject if
 *      another transfer already completed after this one was approved
 *   3. apply the type-specific effect: ownership-moving types close/open the
 *      ownership ledger; a plot transfer moves the booking to the new plot;
 *      a nominee change touches neither
 *   4. mark the request COMPLETED + write the audit events
 *
 * Historical `booking_buyers` / buyer rows are never mutated. Never creates
 * or deletes a booking — only an ownership-moving type's owning buyer, or a
 * plot transfer's plot association, ever changes.
 */
class CompleteTransferAction
{
    use RunsInTransaction;

    public function __construct(
        private readonly PlotOwnershipService $ownership,
        private readonly PlotTransferService $plotTransfer,
    ) {}

    public function handle(TransferRequest $transfer, User $actor): TransferRequest
    {
        if (! $actor->can('transfer.complete')) {
            throw new DomainException('You are not authorised to complete transfers.');
        }

        return $this->transaction(function () use ($transfer, $actor): TransferRequest {
            /** @var TransferRequest $locked */
            $locked = TransferRequest::query()->whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === TransferRequestStatus::Completed) {
                return $locked; // idempotent — no duplicate completion
            }

            if ($locked->status !== TransferRequestStatus::Approved) {
                throw new DomainException("A {$locked->status->label()} transfer cannot be completed.");
            }

            // Lock the plot(s) so two approved transfers cannot both complete
            // against the same plot.
            $oldPlot = Plot::query()->whereKey($locked->plot_id)->lockForUpdate()->firstOrFail();
            $newPlot = $locked->new_plot_id !== null
                ? Plot::query()->whereKey($locked->new_plot_id)->lockForUpdate()->firstOrFail()
                : null;

            $conflict = TransferRequest::query()
                ->where('booking_id', $locked->booking_id)
                ->whereKeyNot($locked->getKey())
                ->where('status', TransferRequestStatus::Completed->value)
                ->where('completed_at', '>=', $locked->approved_at)
                ->exists();

            if ($conflict) {
                throw new DomainException('Another transfer on this booking has already completed since this one was approved.');
            }

            $locked->load('booking', 'plot', 'newBuyer');

            if ($locked->transfer_type->movesPlot()) {
                if ($newPlot === null) {
                    throw new DomainException('No target plot is set on this transfer.');
                }

                // Re-checked here too, immediately before completion — never
                // trust the Draft screen or the approval-time snapshot.
                if ($locked->booking->isPossessionDone()) {
                    throw new DomainException('Possession is already Done for this booking — the plot cannot be changed anymore.');
                }

                if ($locked->booking->isRegistryDone()) {
                    throw new DomainException('Registry is already Done for this booking — the plot cannot be changed anymore.');
                }

                $this->plotTransfer->applyPlotChange($locked, $oldPlot, $newPlot, $actor);
            } elseif ($locked->transfer_type->movesOwnership()) {
                $this->ownership->applyTransfer($locked, $actor);
            }

            $locked->forceFill([
                'status' => TransferRequestStatus::Completed,
                'completed_at' => now(),
                'completed_by' => $actor->id,
            ])->save();

            PossessionTimeline::record(
                PossessionActivityType::TransferCompleted,
                "Transfer {$locked->request_number} completed.",
                $locked->booking, $newPlot ?? $locked->plot, $locked->newBuyer,
                [
                    'transfer_request_id' => $locked->id,
                    'moves_ownership' => $locked->transfer_type->movesOwnership(),
                    'moves_plot' => $locked->transfer_type->movesPlot(),
                ],
                $actor,
            );

            Log::info('transfer_request.completed', ['transfer_request_id' => $locked->id, 'by' => $actor->id]);

            return $locked;
        });
    }
}
