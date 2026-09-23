<?php

declare(strict_types=1);

namespace App\Services\Transfer;

use App\Enums\OwnershipType;
use App\Enums\PlotStatus;
use App\Enums\PossessionActivityType;
use App\Enums\RegistryStatus;
use App\Exceptions\DomainException;
use App\Models\Plot;
use App\Models\PlotOwnershipHistory;
use App\Models\TransferRequest;
use App\Models\User;
use App\Services\Ownership\PlotOwnershipService;
use App\Support\Possession\PossessionTimeline;
use Illuminate\Support\Carbon;

/**
 * Moves a booking from its current plot to a new one (PLOT_TRANSFER, M10
 * extension). The buyer never changes — only which plot the booking sits on.
 *
 * Callers MUST already hold row locks on the booking, the transfer request
 * and BOTH plots, inside a transaction (see ExecutePlotTransferAction /
 * CompleteTransferAction) — this class re-verifies the plots' state against
 * those locked rows rather than trusting anything computed earlier.
 *
 * Inventory rule: the OLD plot is always released to AVAILABLE; the NEW plot
 * takes the booking's current inventory state — SOLD when the booking is
 * already registered (Registry DONE, or the old plot was already Sold /
 * legacy Possession-completed), otherwise BOOKED. Registry and Possession
 * statuses themselves live on the booking and are never touched here, so
 * they carry over to the new plot unchanged.
 *
 * What moves with the booking (CURRENT references): bookings.plot_id /
 * block_id / project_id, and the active plot_ownership_history period(s)
 * (closed on the old plot, re-opened on the new — append-only). What stays
 * on the old plot (HISTORICAL references): transfer_requests.plot_id,
 * possession_activities.plot_id, and any legacy possession_cases.plot_id —
 * each records where something actually happened at the time.
 */
class PlotTransferService
{
    /** Plot states a confirmed booking's current plot may be released from. */
    private const RELEASABLE_PLOT_STATES = [
        PlotStatus::Booked,
        PlotStatus::Sold,
        PlotStatus::PossessionCompleted,
    ];

    public function __construct(private readonly PlotOwnershipService $ownership) {}

    /**
     * @param  Plot  $oldPlot  locked, the plot the booking is currently on
     * @param  Plot  $newPlot  locked, the target plot
     */
    public function applyPlotChange(TransferRequest $transfer, Plot $oldPlot, Plot $newPlot, User $actor): void
    {
        $booking = $transfer->booking;

        if ($booking === null || $booking->plot_id !== $oldPlot->id) {
            throw new DomainException('The old plot is no longer the booking\'s current plot.');
        }

        if ($oldPlot->id === $newPlot->id) {
            throw new DomainException('The new plot must be different from the current plot.');
        }

        if (! $newPlot->is_active || $newPlot->status !== PlotStatus::Available) {
            throw new DomainException("The target plot is {$newPlot->status->label()} and is no longer available.");
        }

        if (! in_array($oldPlot->status, self::RELEASABLE_PLOT_STATES, true)) {
            throw new DomainException("The current plot is {$oldPlot->status->label()} and cannot be released.");
        }

        $this->ownership->ensureAllotment($booking, $actor);

        $now = Carbon::now();

        $newPlotStatus = $booking->registry_status === RegistryStatus::Done || $oldPlot->status !== PlotStatus::Booked
            ? PlotStatus::Sold
            : PlotStatus::Booked;

        // --- Release the old plot, claim the new one ------------------
        $oldPlot->forceFill(['status' => PlotStatus::Available])->save();

        $newPlot->forceFill(array_merge(
            ['status' => $newPlotStatus],
            Plot::clearedHoldAttributes(),
        ))->save();

        // --- The SAME booking now points at the new plot ---------------
        $booking->forceFill([
            'plot_id' => $newPlot->id,
            'block_id' => $newPlot->block_id,
            'project_id' => $newPlot->project_id,
        ])->save();

        // --- Mirror every active ownership period onto the new plot ----
        // (same buyer(s), same share — ownership itself never changes).
        $active = PlotOwnershipHistory::query()
            ->where('booking_id', $booking->id)
            ->whereNull('ended_at')
            ->lockForUpdate()
            ->get();

        foreach ($active as $period) {
            $period->forceFill(['ended_at' => $now])->save();

            PlotOwnershipHistory::create([
                'plot_id' => $newPlot->id,
                'booking_id' => $booking->id,
                'buyer_id' => $period->buyer_id,
                'ownership_type' => OwnershipType::PlotChange,
                'is_primary' => $period->is_primary,
                'ownership_percentage' => $period->ownership_percentage,
                'started_at' => $now,
                'ended_at' => null,
                'source_type' => 'transfer_request',
                'source_id' => $transfer->id,
                'created_by' => $actor->id,
            ]);
        }

        PossessionTimeline::record(
            PossessionActivityType::PlotChanged,
            "Plot changed via {$transfer->request_number}: {$oldPlot->plot_number} → {$newPlot->plot_number}.",
            $booking, $newPlot, null,
            ['transfer_request_id' => $transfer->id, 'old_plot_id' => $oldPlot->id, 'new_plot_id' => $newPlot->id],
            $actor,
        );
    }
}
