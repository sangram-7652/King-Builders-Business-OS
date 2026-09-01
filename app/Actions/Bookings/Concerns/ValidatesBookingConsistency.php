<?php

declare(strict_types=1);

namespace App\Actions\Bookings\Concerns;

use App\Enums\BookingStatus;
use App\Enums\PlotStatus;
use App\Exceptions\DomainException;
use App\Models\Block;
use App\Models\Booking;
use App\Models\Plot;
use App\Models\User;
use Illuminate\Support\Arr;

/**
 * Shared guard rails for the booking actions: the project / block / plot must
 * form a real hierarchy, and the plot must actually be bookable.
 */
trait ValidatesBookingConsistency
{
    /**
     * Reject "Project A + a Block that belongs to Project B", or a plot that
     * is not in the given block.
     */
    protected function assertHierarchyConsistent(int $projectId, int $blockId, Plot $plot): void
    {
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
     * A price line flagged `metadata.override` requires the `pricing.override`
     * permission and a non-empty reason.
     *
     * @param  array<int, array<string, mixed>>  $components
     */
    protected function assertOverrideAuthorised(array $components, User $actor): void
    {
        foreach ($components as $component) {
            if (! Arr::get($component, 'metadata.override', false)) {
                continue;
            }

            if (! $actor->can('pricing.override')) {
                throw new DomainException('You are not authorised to override booking pricing.');
            }

            if (trim((string) Arr::get($component, 'metadata.reason', '')) === '') {
                throw new DomainException('A price override needs a reason.');
            }
        }
    }
}
