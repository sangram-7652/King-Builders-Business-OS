<?php

declare(strict_types=1);

namespace App\Livewire\Bookings;

use App\Actions\Partners\RemoveBookingPartnerAttribution;
use App\Actions\Partners\SetBookingPartnerAttribution;
use App\Enums\BookingAttributionRole;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\Lead;
use App\Models\Partner;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Manage a booking's channel-partner split (M14.2) — `/bookings/{booking}/partners`.
 *
 * Rows carry partner + share %; exactly one is the primary; the live total must
 * reach 100% before saving. Saving supersedes the previous split rather than
 * editing it, so history (and any commission snapshot) is preserved.
 */
#[Layout('components.layouts.app')]
class BookingPartners extends Component
{
    public Booking $booking;

    /** @var list<array{partner_id: string, share_percentage: string, role: string}> */
    public array $rows = [];

    /** Partner carried over from the originating lead, if the booking has no split yet. */
    public ?int $suggestedPartnerId = null;

    public ?string $suggestedPartnerLabel = null;

    public function mount(Booking $booking): void
    {
        $this->authorize('view', $booking);
        $this->authorize('attributePartners', $booking);
        $this->booking = $booking;
        $this->loadRows();
        $this->resolveSuggestion();
    }

    private function resolveSuggestion(): void
    {
        if ($this->rows !== []) {
            return;
        }

        $buyerIds = $this->booking->bookingBuyers()->pluck('buyer_id');

        $partner = Partner::query()->active()
            ->whereIn('id', Lead::query()
                ->whereIn('buyer_id', $buyerIds)
                ->whereNotNull('partner_id')
                ->select('partner_id'))
            ->orderByDesc('id')
            ->first();

        if ($partner !== null) {
            $this->suggestedPartnerId = $partner->id;
            $this->suggestedPartnerLabel = $partner->displayName().' ('.$partner->partner_code.')';
        }
    }

    public function applySuggestion(): void
    {
        if ($this->suggestedPartnerId === null) {
            return;
        }

        $this->rows = [[
            'partner_id' => (string) $this->suggestedPartnerId,
            'share_percentage' => '100',
            'role' => BookingAttributionRole::Primary->value,
        ]];
    }

    private function loadRows(): void
    {
        $this->rows = $this->booking->partnerAttributions()->get()
            ->map(fn ($a) => [
                'partner_id' => (string) $a->partner_id,
                'share_percentage' => rtrim(rtrim(number_format((float) $a->share_percentage, 2), '0'), '.'),
                'role' => $a->role->value,
            ])->values()->all();
    }

    public function addRow(): void
    {
        $this->rows[] = [
            'partner_id' => '',
            'share_percentage' => '',
            'role' => $this->rows === [] ? BookingAttributionRole::Primary->value : BookingAttributionRole::CoBroker->value,
        ];
    }

    public function removeRow(int $index): void
    {
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);
    }

    public function setPrimary(int $index): void
    {
        foreach ($this->rows as $i => $row) {
            $this->rows[$i]['role'] = $i === $index
                ? BookingAttributionRole::Primary->value
                : BookingAttributionRole::CoBroker->value;
        }
    }

    public function getTotalProperty(): string
    {
        $total = '0.00';
        foreach ($this->rows as $row) {
            $share = $row['share_percentage'];
            if (is_numeric($share)) {
                $total = bcadd($total, number_format((float) $share, 2, '.', ''), 2);
            }
        }

        return $total;
    }

    public function save(): void
    {
        $this->authorize('attributePartners', $this->booking);

        try {
            app(SetBookingPartnerAttribution::class)->handle($this->booking, $this->rows, auth()->user());
            $this->booking = $this->booking->fresh();
            $this->loadRows();
            $this->dispatch('toast', message: 'Partner attribution saved.', variant: 'success');
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
            $this->rows = [];
            $this->dispatch('toast', message: 'Booking marked as a direct sale.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function render(): View
    {
        return view('livewire.bookings.booking-partners', [
            'booking' => $this->booking->load('partnerAttributions.partner', 'partnerAttributionHistory.partner', 'partnerAttributionHistory.attributedBy'),
            'partners' => Partner::query()->active()->orderBy('name')->get(['id', 'name', 'company_name', 'partner_code']),
            'roles' => BookingAttributionRole::options(),
        ])->title("Partners · {$this->booking->booking_number}");
    }
}
