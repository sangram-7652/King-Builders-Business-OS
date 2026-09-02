<?php

declare(strict_types=1);

namespace App\Services\Ownership;

use App\Enums\OwnershipType;
use App\Enums\PossessionActivityType;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\PlotOwnershipHistory;
use App\Models\TransferRequest;
use App\Models\User;
use App\Support\Possession\PossessionTimeline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The single writer for `plot_ownership_history` (M10). Append-only:
 *
 *   - the original allotment period(s) are derived once from `booking_buyers`
 *   - a completed transfer closes the active period(s) (`ended_at`) and opens
 *     new ones for the incoming buyer
 *   - `booking_buyers` (the historical M6 allotment) is never mutated
 *
 * Callers MUST already hold a row lock on the plot / transfer inside a
 * transaction (see CompleteTransferAction).
 */
class PlotOwnershipService
{
    /**
     * Lazily materialise the original allotment ownership from the M6
     * `booking_buyers` pivot. Idempotent — does nothing if periods already
     * exist for the booking.
     */
    public function ensureAllotment(Booking $booking, ?User $actor = null): void
    {
        if (PlotOwnershipHistory::query()->where('booking_id', $booking->getKey())->exists()) {
            return;
        }

        $booking->loadMissing('bookingBuyers');
        $startedAt = $booking->confirmed_at ?? $booking->booking_date ?? $booking->created_at ?? now();

        foreach ($booking->bookingBuyers as $bb) {
            PlotOwnershipHistory::create([
                'plot_id' => $booking->plot_id,
                'booking_id' => $booking->id,
                'buyer_id' => $bb->buyer_id,
                'ownership_type' => OwnershipType::Allotment,
                'is_primary' => (bool) $bb->is_primary,
                'ownership_percentage' => $bb->ownership_percentage,
                'started_at' => $startedAt,
                'ended_at' => null,
                'source_type' => 'booking',
                'source_id' => $booking->id,
                'created_by' => $actor?->id,
            ]);
        }
    }

    /** @return Collection<int, PlotOwnershipHistory> the current (open) owner period(s) for a booking */
    public function currentOwners(Booking $booking): Collection
    {
        return PlotOwnershipHistory::query()
            ->where('booking_id', $booking->getKey())
            ->whereNull('ended_at')
            ->orderByDesc('is_primary')
            ->get();
    }

    /**
     * Close the active ownership period(s) for the booking and open a new one
     * for the transfer's incoming buyer. Assumes the caller holds locks.
     */
    public function applyTransfer(TransferRequest $transfer, User $actor): void
    {
        $this->ensureAllotment($transfer->booking, $actor);

        $now = Carbon::now();

        $active = PlotOwnershipHistory::query()
            ->where('booking_id', $transfer->booking_id)
            ->whereNull('ended_at')
            ->lockForUpdate()
            ->get();

        if ($active->isEmpty()) {
            throw new DomainException('The plot has no current owner to transfer from.');
        }

        // Percentage transferring to the new buyer = the sum of the closed periods.
        $percentage = $active->sum(fn ($p) => (float) $p->ownership_percentage);
        $wasPrimary = $active->contains(fn ($p) => (bool) $p->is_primary);

        foreach ($active as $period) {
            $period->forceFill(['ended_at' => $now])->save();
        }

        // Guard: the incoming buyer must not already hold an active period here.
        $alreadyOwns = PlotOwnershipHistory::query()
            ->where('booking_id', $transfer->booking_id)
            ->where('buyer_id', $transfer->new_buyer_id)
            ->whereNull('ended_at')
            ->exists();

        if ($alreadyOwns) {
            throw new DomainException('The incoming buyer already holds an active ownership period for this plot.');
        }

        PlotOwnershipHistory::create([
            'plot_id' => $transfer->plot_id,
            'booking_id' => $transfer->booking_id,
            'buyer_id' => $transfer->new_buyer_id,
            'ownership_type' => OwnershipType::Transfer,
            'is_primary' => $wasPrimary,
            'ownership_percentage' => $percentage > 0 ? $percentage : 100,
            'started_at' => $now,
            'ended_at' => null,
            'source_type' => 'transfer_request',
            'source_id' => $transfer->id,
            'created_by' => $actor->id,
        ]);

        PossessionTimeline::record(
            PossessionActivityType::OwnershipChanged,
            "Ownership of plot moved via {$transfer->request_number}.",
            $transfer->booking, $transfer->plot, $transfer->newBuyer()->first(),
            ['transfer_request_id' => $transfer->id, 'was_primary' => $wasPrimary],
            $actor,
        );
    }
}
