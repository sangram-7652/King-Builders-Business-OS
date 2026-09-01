<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Actions\Bookings\Concerns\PersistsBookingPricing;
use App\Actions\Bookings\Concerns\ValidatesBookingConsistency;
use App\Enums\BookingStatus;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\Plot;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Updates an editable booking (DRAFT / PENDING): booking date, notes, the
 * pricing config, the buyer set, and an optional DRAFT → PENDING promotion.
 * The plot / project / block are fixed at creation.
 *
 * The whole thing runs in one transaction with the plot row locked, so a
 * DRAFT → PENDING promotion cannot race another booking onto the same plot.
 */
class UpdateBookingAction
{
    use PersistsBookingPricing;
    use RunsInTransaction;
    use ValidatesBookingConsistency;

    public function __construct(private readonly SyncBookingBuyersAction $syncBuyers) {}

    /**
     * @param  array<string, mixed>  $data  already validated
     */
    public function handle(Booking $booking, array $data, User $actor): Booking
    {
        if (! $booking->isEditable()) {
            throw new DomainException('Only a draft or pending booking can be edited.');
        }

        $this->assertOverrideAuthorised($data['pricing']['components'] ?? [], $actor);

        return $this->transaction(function () use ($booking, $data, $actor): Booking {
            /** @var Booking $locked */
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isEditable()) {
                throw new DomainException('Only a draft or pending booking can be edited.');
            }

            /** @var Plot $plot */
            $plot = Plot::query()->whereKey($locked->plot_id)->lockForUpdate()->firstOrFail();

            $target = BookingStatus::tryFrom((string) ($data['status'] ?? $locked->status->value)) ?? $locked->status;

            if ($target !== $locked->status && ! $locked->canTransitionTo($target)) {
                throw new DomainException("A {$locked->status->label()} booking cannot move to {$target->label()} here.");
            }

            if ($target->reservesPlot() && ! $locked->status->reservesPlot()) {
                $this->assertPlotBookable($plot);
                $this->assertNoLiveBooking($plot->id, $locked->id);
            }

            $locked->fill([
                'booking_date' => $data['booking_date'] ?? $locked->booking_date,
                'notes' => $data['notes'] ?? $locked->notes,
            ]);
            $locked->status = $target;
            $locked->save();

            $this->applyPricing($locked, $data['pricing'] ?? CalculateBookingPriceAction::configFromBooking($locked));
            $this->syncBuyers->handle($locked, $data['buyers'] ?? $this->currentBuyerRows($locked));

            Log::info('booking.updated', [
                'booking_id' => $locked->id,
                'status' => $locked->status->value,
                'final_amount' => $locked->final_amount,
                'by' => $actor->id,
            ]);

            return $locked->load(['project', 'block', 'plot', 'bookingBuyers.buyer', 'priceLines']);
        });
    }

    /**
     * @return array<int, array{buyer_id: int, ownership_percentage: string, is_primary: bool}>
     */
    private function currentBuyerRows(Booking $booking): array
    {
        return $booking->bookingBuyers()->get()->map(fn ($bb) => [
            'buyer_id' => $bb->buyer_id,
            'ownership_percentage' => (string) $bb->ownership_percentage,
            'is_primary' => (bool) $bb->is_primary,
        ])->all();
    }
}
