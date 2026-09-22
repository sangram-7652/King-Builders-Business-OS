<?php

declare(strict_types=1);

namespace App\Livewire\Bookings;

use App\Actions\Bookings\CancelBookingAction;
use App\Actions\Bookings\ConfirmBookingAction;
use App\Actions\Bookings\DeleteBookingAction;
use App\Actions\Bookings\OverrideBookingPriceAction;
use App\Actions\Bookings\SubmitBookingAction;
use App\Enums\TransferRequestStatus;
use App\Enums\TransferType;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Services\Payments\PaymentLedger;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class BookingShow extends Component
{
    public Booking $booking;

    public bool $showCancel = false;

    public string $cancelReason = '';

    public bool $showOverride = false;

    public string $overrideAmount = '';

    public string $overrideReason = '';

    public function mount(Booking $booking): void
    {
        $this->authorize('view', $booking);
        $this->booking = $booking;
        $this->refreshBooking();
    }

    private function refreshBooking(): void
    {
        $this->booking = $this->booking->fresh([
            'project', 'block', 'plot', 'createdBy', 'confirmedBy', 'cancelledBy', 'priceOverrideBy',
            'bookingBuyers.buyer', 'priceLines',
        ]);
    }

    public function submit(): void
    {
        $this->authorize('update', $this->booking);

        try {
            app(SubmitBookingAction::class)->handle($this->booking, auth()->user());
            $this->refreshBooking();
            $this->dispatch('toast', message: 'Booking submitted for confirmation.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function confirm(): void
    {
        $this->authorize('confirm', $this->booking);

        try {
            app(ConfirmBookingAction::class)->handle($this->booking, auth()->user());
            $this->refreshBooking();
            $this->dispatch('toast', message: 'Booking confirmed. Plot is now booked and pricing is frozen.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function openCancel(): void
    {
        $this->authorize('cancel', $this->booking);
        $this->cancelReason = '';
        $this->showCancel = true;
    }

    public function closeCancel(): void
    {
        $this->reset('showCancel', 'cancelReason');
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->authorize('cancel', $this->booking);
        $this->validate(['cancelReason' => ['nullable', 'string', 'max:255']]);

        $wasConfirmed = $this->booking->isConfirmed();

        try {
            app(CancelBookingAction::class)->handle($this->booking, auth()->user(), $this->cancelReason ?: null);
            $this->refreshBooking();
            $this->dispatch('toast', message: $wasConfirmed
                ? 'Booking cancelled successfully. Plot is now available.'
                : 'Booking cancelled.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }

        $this->closeCancel();
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->booking);

        try {
            app(DeleteBookingAction::class)->handle($this->booking, auth()->user());
            $this->dispatch('toast', message: 'Booking deleted.', variant: 'success');
            $this->redirectRoute('bookings.index', navigate: true);
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function openOverride(): void
    {
        $this->authorize('overridePricing', $this->booking);
        $this->overrideAmount = (string) $this->booking->final_amount;
        $this->overrideReason = '';
        $this->showOverride = true;
    }

    public function closeOverride(): void
    {
        $this->reset('showOverride', 'overrideAmount', 'overrideReason');
        $this->resetValidation();
    }

    public function applyOverride(): void
    {
        $this->authorize('overridePricing', $this->booking);

        $this->validate([
            'overrideAmount' => ['required', 'numeric', 'min:0'],
            'overrideReason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        try {
            app(OverrideBookingPriceAction::class)->handle(
                $this->booking,
                $this->overrideAmount,
                $this->overrideReason,
                auth()->user(),
            );
            $this->refreshBooking();
            $this->dispatch('toast', message: 'Price override applied.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }

        $this->closeOverride();
    }

    public function render(): View
    {
        $summary = $this->booking->isConfirmed()
            ? app(PaymentLedger::class)->summary($this->booking)
            : null;

        $plotTransferHistory = $this->booking->transferRequests()
            ->where('transfer_type', TransferType::PlotTransfer->value)
            ->where('status', TransferRequestStatus::Completed->value)
            ->with(['plot:id,plot_number', 'newPlot:id,plot_number', 'completedBy:id,name'])
            ->orderByDesc('completed_at')
            ->get();

        return view('livewire.bookings.booking-show', [
            'financials' => $summary,
            'plotTransferHistory' => $plotTransferHistory,
        ])->title($this->booking->booking_number);
    }
}
