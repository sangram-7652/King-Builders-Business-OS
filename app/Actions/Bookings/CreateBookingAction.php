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
use App\Support\Sequences\SequenceGenerator;
use Illuminate\Support\Facades\Log;

/**
 * Creates a booking (DRAFT or PENDING) inside one transaction, with the plot
 * row locked FOR UPDATE for the whole operation.
 *
 * DRAFT / PENDING never flip Plot::status — that happens only on confirmation —
 * but PENDING does claim the plot: no second booking may be PENDING/CONFIRMED
 * for it (checked here AND guaranteed by the `active_plot_id` unique index).
 */
class CreateBookingAction
{
    use PersistsBookingPricing;
    use RunsInTransaction;
    use ValidatesBookingConsistency;

    public function __construct(
        private readonly SequenceGenerator $sequences,
        private readonly SyncBookingBuyersAction $syncBuyers,
    ) {}

    /**
     * @param  array<string, mixed>  $data  already validated by the caller
     */
    public function handle(array $data, User $actor): Booking
    {
        $target = BookingStatus::tryFrom((string) ($data['status'] ?? BookingStatus::Draft->value))
            ?? BookingStatus::Draft;

        if (! in_array($target, [BookingStatus::Draft, BookingStatus::Pending], true)) {
            throw new DomainException('A new booking can only start as a draft or pending.');
        }

        $this->assertOverrideAuthorised($data['pricing']['components'] ?? [], $actor);

        return $this->transaction(function () use ($data, $actor, $target): Booking {
            /** @var Plot $plot */
            $plot = Plot::query()->whereKey($data['plot_id'])->lockForUpdate()->firstOrFail();

            $this->assertHierarchyConsistent((int) $data['project_id'], (int) $data['block_id'], $plot);
            $this->assertPlotBookable($plot);

            if ($target->reservesPlot()) {
                $this->assertNoLiveBooking($plot->id);
            }

            $booking = Booking::create([
                'booking_number' => Booking::formatCode($this->sequences->next(Booking::SEQUENCE_KEY)),
                'project_id' => $plot->project_id,
                'block_id' => $plot->block_id,
                'plot_id' => $plot->id,
                'booking_date' => $data['booking_date'],
                'status' => $target,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            $this->applyPricing($booking, $data['pricing'] ?? []);
            $this->syncBuyers->handle($booking, $data['buyers'] ?? []);

            Log::info('booking.created', [
                'booking_id' => $booking->id,
                'booking_number' => $booking->booking_number,
                'plot_id' => $plot->id,
                'status' => $target->value,
                'final_amount' => $booking->final_amount,
                'by' => $actor->id,
            ]);

            return $booking->load(['project', 'block', 'plot', 'bookingBuyers.buyer', 'priceLines']);
        });
    }
}
