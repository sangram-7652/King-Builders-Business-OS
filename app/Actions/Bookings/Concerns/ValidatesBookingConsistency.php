<?php

declare(strict_types=1);

namespace App\Actions\Bookings\Concerns;

use App\Actions\Bookings\OverrideBookingPriceAction;
use App\Enums\BookingStatus;
use App\Enums\PlotStatus;
use App\Exceptions\DomainException;
use App\Models\Block;
use App\Models\Booking;
use App\Models\Plot;
use App\Support\Pricing\PricingArea;

/**
 * Shared guard rails for the booking actions: the project / block / plot must
 * form a real hierarchy, and the plot must actually be bookable.
 */
trait ValidatesBookingConsistency
{
    /**
     * Reject "Project A + a Block that belongs to Project B", or a plot that
     * is not in the given block. A Block is OPTIONAL — `$blockId === null`
     * means the plot is expected to be a DIRECT project plot (no Block).
     */
    protected function assertHierarchyConsistent(int $projectId, ?int $blockId, Plot $plot): void
    {
        if ($blockId === null) {
            if ($plot->block_id !== null || $plot->project_id !== $projectId) {
                throw new DomainException('That plot does not belong to the selected project.');
            }

            return;
        }

        $block = Block::query()->find($blockId);

        if ($block === null || $block->project_id !== $projectId) {
            throw new DomainException('That block does not belong to the selected project.');
        }

        if ($plot->block_id !== $blockId || $plot->project_id !== $projectId) {
            throw new DomainException('That plot does not belong to the selected project and block.');
        }
    }

    /**
     * A plot can be booked only while it is active and AVAILABLE or on HOLD.
     * Call this against the row already locked FOR UPDATE.
     */
    protected function assertPlotBookable(Plot $plot): void
    {
        if (! $plot->is_active) {
            throw new DomainException('That plot is archived and cannot be booked.');
        }

        if (! in_array($plot->status, [PlotStatus::Available, PlotStatus::Hold], true)) {
            throw new DomainException("That plot is {$plot->status->label()} and is no longer available to book.");
        }
    }

    /**
     * No other booking may be PENDING or CONFIRMED for this plot. `$ignoreId`
     * skips the booking being confirmed/updated itself.
     */
    protected function assertNoLiveBooking(int $plotId, ?int $ignoreId = null): void
    {
        $exists = Booking::query()
            ->where('plot_id', $plotId)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->whereIn('status', [BookingStatus::Pending->value, BookingStatus::Confirmed->value])
            ->exists();

        if ($exists) {
            throw new DomainException('That plot already has a live booking.');
        }
    }

    /**
     * The booking's pricing quantity comes from the PLOT, never from the
     * client: `base_area` = the plot's area converted to sq ft (the unit
     * every booking rate is in). A submitted base_area is ignored — there is
     * no manual-area override; the only price adjustment is the audited
     * final-amount override ({@see OverrideBookingPriceAction}).
     *
     * @param  array<string, mixed>  $pricing
     * @return array<string, mixed>
     */
    protected function withPlotPricingArea(array $pricing, Plot $plot): array
    {
        $pricing['base_area'] = PricingArea::fromPlot($plot);

        return $pricing;
    }

    /**
     * Refuse to freeze a price computed from an area that no longer matches
     * the plot (e.g. the plot's area/unit was edited after the booking was
     * priced, or a legacy booking carries a hand-entered area). Never
     * silently re-prices — the operator re-saves the booking to re-price it.
     */
    protected function assertPricingAreaCurrent(Booking $booking, Plot $plot): void
    {
        $expected = PricingArea::fromPlot($plot);

        if (! PricingArea::equals((string) $booking->base_area, $expected)) {
            throw new DomainException(
                "This booking was priced on {$booking->base_area} sq ft, but plot {$plot->plot_number} is now {$expected} sq ft ({$plot->areaLabel()}). Edit and save the booking to re-price it, then confirm."
            );
        }
    }
}
