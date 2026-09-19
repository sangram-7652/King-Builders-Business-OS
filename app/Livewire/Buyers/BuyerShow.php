<?php

declare(strict_types=1);

namespace App\Livewire\Buyers;

use App\Actions\Buyers\ChangeBuyerStatus;
use App\Actions\Customers\InviteCustomerToPortal;
use App\Actions\Customers\RequestCustomerPasswordReset;
use App\Actions\Customers\SetCustomerPortalAccess;
use App\Enums\BuyerStatus;
use App\Exceptions\DomainException;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Buyer;
use App\Models\Payment;
use App\Models\TransferRequest;
use App\Services\Payments\PaymentLedger;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class BuyerShow extends Component
{
    public Buyer $buyer;

    public bool $revealDocuments = false;

    /** The one-time activation / reset link, shown once after generating it. */
    public ?string $portalLink = null;

    public function mount(Buyer $buyer): void
    {
        $this->authorize('view', $buyer);
        $this->buyer = $buyer->load(['state', 'city', 'createdBy']);
    }

    // --- Customer portal access (M15) --------------------------------

    private function runPortal(callable $fn): void
    {
        $this->authorize('managePortal', $this->buyer);

        try {
            $fn();
            $this->buyer = $this->buyer->fresh(['state', 'city', 'createdBy']);
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function invitePortal(): void
    {
        $this->runPortal(function (): void {
            $result = app(InviteCustomerToPortal::class)->handle($this->buyer, auth()->user());
            $this->portalLink = $result['link'];
            $this->dispatch('toast', message: 'Portal invitation created. Copy the link below to share.', variant: 'success');
        });
    }

    public function resetPortalPassword(): void
    {
        $this->runPortal(function (): void {
            $result = app(RequestCustomerPasswordReset::class)->forBuyer($this->buyer, auth()->user());
            $this->portalLink = $result['link'];
            $this->dispatch('toast', message: 'Password reset link created. Copy it below to share.', variant: 'success');
        });
    }

    public function suspendPortal(): void
    {
        $this->runPortal(function (): void {
            app(SetCustomerPortalAccess::class)->suspend($this->buyer, auth()->user());
            $this->portalLink = null;
            $this->dispatch('toast', message: 'Portal access suspended.', variant: 'success');
        });
    }

    public function restorePortal(): void
    {
        $this->runPortal(function (): void {
            app(SetCustomerPortalAccess::class)->restore($this->buyer, auth()->user());
            $this->dispatch('toast', message: 'Portal access restored.', variant: 'success');
        });
    }

    public function toggleReveal(): void
    {
        // Full PAN / Aadhaar require the dedicated permission — always re-checked.
        $this->authorize('viewDocuments', $this->buyer);
        $this->revealDocuments = ! $this->revealDocuments;
    }

    public function changeStatus(string $status): void
    {
        $this->authorize('changeStatus', $this->buyer);

        $target = BuyerStatus::tryFrom($status);
        if ($target === null) {
            return;
        }

        try {
            app(ChangeBuyerStatus::class)->handle($this->buyer, $target);
            $this->buyer = $this->buyer->fresh(['state', 'city', 'createdBy']);
            $this->dispatch('toast', message: "Buyer {$target->label()}.", variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    /**
     * Payment summary across every confirmed booking where this buyer is
     * primary. Figures come straight from the M7 ledger.
     *
     * @return array<string, mixed>
     */
    private function paymentProfile(): array
    {
        $ledger = app(PaymentLedger::class);

        $bookings = $this->buyer->bookings()
            ->wherePivot('is_primary', true)
            ->where('bookings.status', BookingStatus::Confirmed->value)
            ->get();

        $value = Money::zero();
        $paid = Money::zero();
        $outstanding = Money::zero();

        foreach ($bookings as $booking) {
            $value = $value->plus(Money::of($booking->final_amount));
            $paid = $paid->plus($ledger->bookingPaid($booking));
            $outstanding = $outstanding->plus($ledger->bookingOutstanding($booking)->clampToZero());
        }

        $lastPayment = Payment::query()
            ->whereIn('booking_id', $bookings->pluck('id'))
            ->where('status', PaymentStatus::Success->value)
            ->latest('payment_date')
            ->first();

        return [
            'total_bookings' => $bookings->count(),
            'total_value' => $value->store(),
            'total_paid' => $paid->store(),
            'total_outstanding' => $outstanding->store(),
            'last_payment_date' => $lastPayment?->payment_date?->toDateString(),
            'last_payment_amount' => $lastPayment !== null ? Money::of($lastPayment->amount)->store() : null,
        ];
    }

    public function render(): View
    {
        $canViewDocuments = auth()->user()->can('viewDocuments', $this->buyer);

        return view('livewire.buyers.buyer-show', [
            'canViewDocuments' => $canViewDocuments,
            'canManagePortal' => auth()->user()->can('managePortal', $this->buyer),
            'pan' => $this->displaySensitive('pan_number', $canViewDocuments),
            'aadhaar' => $this->displaySensitive('aadhaar_number', $canViewDocuments),
            'allowedStatuses' => $this->buyer->status->allowedTransitions(),
            'paymentProfile' => auth()->user()->can('payments.view')
                ? $this->paymentProfile()
                : null,
            'ownerships' => auth()->user()->can('ownership.view')
                ? $this->buyer->plotOwnerships()->with(['plot:id,plot_number', 'booking:id,booking_number'])->limit(20)->get()
                : collect(),
            'nominees' => $this->buyer->nominees()->limit(10)->get(),
            'transfers' => auth()->user()->can('transfer.view')
                ? TransferRequest::query()
                    ->where(fn ($q) => $q->where('new_buyer_id', $this->buyer->id)->orWhere('current_buyer_id', $this->buyer->id))
                    ->with('booking:id,booking_number')->latest('id')->limit(15)->get()
                : collect(),
        ])->title($this->buyer->fullName());
    }

    private function displaySensitive(string $field, bool $canView): ?string
    {
        $raw = $this->buyer->{$field};

        if ($raw === null || $raw === '') {
            return null;
        }

        if ($this->revealDocuments && $canView) {
            return $field === 'pan_number' ? $this->buyer->pan_number : $this->buyer->aadhaar_number;
        }

        return $field === 'pan_number' ? $this->buyer->maskedPan() : $this->buyer->maskedAadhaar();
    }
}
