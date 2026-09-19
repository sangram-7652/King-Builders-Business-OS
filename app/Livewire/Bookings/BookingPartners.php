<?php

declare(strict_types=1);

namespace App\Livewire\Bookings;

use App\Actions\Partners\RemoveBookingPartnerAttribution;
use App\Actions\Partners\SetBookingPartnerAttribution;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\Partner;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Manage a booking's promoter (M14.2) — `/bookings/{booking}/partners`. One
 * promoter maximum per booking. Saving supersedes the previous attribution
 * rather than editing it, so history (and any commission snapshot) is
 * preserved.
 */
#[Layout('components.layouts.app')]
class BookingPartners extends Component
{
    public Booking $booking;

    public string $partnerId = '';

    public function mount(Booking $booking): void
    {
        $this->authorize('view', $booking);
        $this->authorize('attributePartners', $booking);
        $this->booking = $booking;
        $this->loadPartnerId();
    }

    private function loadPartnerId(): void
    {
        $this->partnerId = (string) ($this->booking->partnerAttributions()->value('partner_id') ?? '');
    }

    public function save(): void
    {
        $this->authorize('attributePartners', $this->booking);

        try {
            app(SetBookingPartnerAttribution::class)->handle(
                $this->booking,
                $this->partnerId !== '' ? (int) $this->partnerId : null,
                auth()->user(),
            );
            $this->booking = $this->booking->fresh();
            $this->loadPartnerId();
            $this->dispatch('toast', message: 'Promoter saved.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function makeDirect(): void
    {
        $this->authorize('attributePartners', $this->booking);

        try {
            app(RemoveBookingPartnerAttribution::class)->handle($this->booking, auth()->user());
            $this->booking = $this->booking->fresh();
            $this->partnerId = '';
            $this->dispatch('toast', message: 'Booking marked as a direct sale.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function render(): View
    {
        return view('livewire.bookings.booking-partners', [
            'booking' => $this->booking->load('partnerAttributions.partner', 'partnerAttributionHistory.partner', 'partnerAttributionHistory.attributedBy'),
            'partners' => Partner::query()->active()->orderBy('name')->get(['id', 'name', 'company_name', 'partner_code', 'commission_percentage']),
        ])->title("Promoter · {$this->booking->booking_number}");
    }
}
