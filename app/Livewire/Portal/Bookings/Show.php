<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Bookings;

use App\Livewire\Portal\Concerns\ResolvesPortalRecords;
use App\Models\Booking;
use App\Services\Payments\PaymentLedger;
use App\Services\Portal\CustomerPaymentSchedule;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.portal')]
class Show extends Component
{
    use ResolvesPortalRecords;

    public Booking $booking;

    public function mount(Booking $booking): void
    {
        // The route id is never trusted — it must belong to this customer.
        $this->booking = $this->bookingOr404($booking->id);
    }

    public function render(): View
    {
        $booking = $this->booking->load([
            'priceLines', 'agreement',
            'documents' => fn ($q) => $q->whereNotNull('current_version_id')->with(['documentType', 'currentVersion']),
        ]);

        $transfer = $booking->transferRequests()
            ->latest('id')->first(['id', 'request_number', 'status', 'transfer_type', 'submitted_at']);

        return view('livewire.portal.bookings.show', [
            'booking' => $booking,
            'financials' => $booking->isConfirmed() ? app(PaymentLedger::class)->summary($booking) : null,
            'schedule' => app(CustomerPaymentSchedule::class)->forBooking($booking),
            'transfer' => $transfer,
        ])->title($booking->booking_number);
    }
}
