<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Payments;

use App\Enums\PaymentStatus;
use App\Livewire\Portal\Concerns\ResolvesPortalRecords;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\Payments\PaymentLedger;
use App\Services\Portal\CustomerPaymentSchedule;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.portal')]
#[Title('Payments')]
class Index extends Component
{
    use ResolvesPortalRecords;
    use WithPagination;

    #[Url]
    public string $tab = 'schedule'; // schedule | history

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['schedule', 'history'], true) ? $tab : 'schedule';
        $this->resetPage();
    }

    public function render(): View
    {
        $bookingIds = $this->customerBookingIds() ?: [0];

        $confirmed = Booking::query()
            ->whereKey($bookingIds)
            ->where('status', 'confirmed')
            ->with(['project:id,name', 'plot:id,plot_number'])
            ->orderByDesc('booking_date')
            ->get();

        $schedule = app(CustomerPaymentSchedule::class);
        $ledger = app(PaymentLedger::class);

        $bookingSchedules = $confirmed->map(fn (Booking $b) => [
            'booking' => $b,
            'summary' => $ledger->summary($b),
            'schedule' => $schedule->forBooking($b),
        ]);

        $payments = $this->tab === 'history'
            ? Payment::query()
                ->whereIn('booking_id', $bookingIds)
                ->where('status', PaymentStatus::Success->value)
                ->with(['paymentMode:id,name', 'booking:id,booking_number', 'receipt:id,payment_id,receipt_number'])
                ->orderByDesc('payment_date')->orderByDesc('id')
                ->paginate((int) config('portal.per_page', 15))
            : null;

        return view('livewire.portal.payments.index', [
            'bookingSchedules' => $bookingSchedules,
            'payments' => $payments,
        ]);
    }
}
