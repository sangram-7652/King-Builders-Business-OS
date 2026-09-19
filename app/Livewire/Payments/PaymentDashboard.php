<?php

declare(strict_types=1);

namespace App\Livewire\Payments;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\Payments\PaymentLedger;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Finance')]
class PaymentDashboard extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', Payment::class);
    }

    public function render(): View
    {
        $ledger = app(PaymentLedger::class);

        $bookings = Booking::query()
            ->where('status', BookingStatus::Confirmed->value)
            ->with(['primaryBookingBuyer.buyer', 'project:id,name'])
            ->get();

        $totalOutstanding = Money::zero();
        $rows = [];

        foreach ($bookings as $booking) {
            $summary = $ledger->summary($booking);
            $totalOutstanding = $totalOutstanding->plus($summary->outstanding->clampToZero());

            if ($summary->outstanding->isPositive()) {
                $rows[] = ['booking' => $booking, 'summary' => $summary];
            }
        }

        usort($rows, fn ($a, $b) => (float) $b['summary']->outstanding->store() <=> (float) $a['summary']->outstanding->store());

        return view('livewire.payments.payment-dashboard', [
            'totalOutstanding' => $totalOutstanding,
            'confirmedBookings' => $bookings->count(),
            'rows' => array_slice($rows, 0, 25),
            'pendingVerification' => Payment::query()->where('status', PaymentStatus::Pending->value)
                ->with(['booking:id,booking_number', 'paymentMode:id,name'])
                ->orderBy('payment_date')->limit(15)->get(),
            'recentPayments' => Payment::query()->where('status', PaymentStatus::Success->value)
                ->with(['booking:id,booking_number'])
                ->orderByDesc('verified_at')->limit(10)->get(),
        ]);
    }
}
