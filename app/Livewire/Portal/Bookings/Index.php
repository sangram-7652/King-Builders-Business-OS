<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Bookings;

use App\Enums\BookingStatus;
use App\Livewire\Portal\Concerns\ResolvesPortalRecords;
use App\Models\Booking;
use App\Services\Payments\PaymentLedger;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.portal')]
#[Title('My bookings')]
class Index extends Component
{
    use ResolvesPortalRecords;
    use WithPagination;

    public function render(): View
    {
        $bookings = Booking::query()
            ->whereKey($this->customerBookingIds() ?: [0])
            ->with(['project:id,name', 'block:id,name', 'plot:id,plot_number'])
            ->orderByDesc('booking_date')
            ->orderByDesc('id')
            ->paginate((int) config('portal.per_page', 15));

        $ledger = app(PaymentLedger::class);
        $financials = [];
        foreach ($bookings as $booking) {
            $financials[$booking->id] = $booking->status === BookingStatus::Confirmed
                ? $ledger->summary($booking)
                : null;
        }

        return view('livewire.portal.bookings.index', [
            'bookings' => $bookings,
            'financials' => $financials,
        ]);
    }
}
