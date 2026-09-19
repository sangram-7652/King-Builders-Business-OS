<?php

declare(strict_types=1);

namespace App\Livewire\Bookings;

use App\Actions\Commission\GenerateCommissionCases;
use App\Actions\Commission\RecalculateCommissionCase;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\CommissionCase;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Per-booking commission view (M14.4) — `/bookings/{booking}/commission`.
 * Generate the cases for the booking's partner split, see each partner's
 * immutable calculation snapshot, recalculate a still-open case.
 */
#[Layout('components.layouts.app')]
class BookingCommission extends Component
{
    public Booking $booking;

    public function mount(Booking $booking): void
    {
        $this->authorize('view', $booking);
        abort_unless(auth()->user()->can('commission.view'), 403);
        $this->booking = $booking;
    }

    public function generate(): void
    {
        $this->authorize('generate', CommissionCase::class);

        try {
            $cases = app(GenerateCommissionCases::class)->handle($this->booking, auth()->user());
            $this->dispatch('toast', message: "{$cases->count()} commission case(s) generated.", variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function recalculate(int $caseId): void
    {
        $case = $this->booking->commissionCases()->findOrFail($caseId);
        $this->authorize('recalculate', $case);

        try {
            app(RecalculateCommissionCase::class)->handle($case, auth()->user());
            $this->dispatch('toast', message: 'Commission recalculated.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function render(): View
    {
        $cases = $this->booking->commissionCases()
            ->with(['partner:id,name,company_name,partner_code,commission_percentage', 'currentCalculation'])
            ->get();

        return view('livewire.bookings.booking-commission', [
            'booking' => $this->booking->load('partnerAttributions.partner'),
            'cases' => $cases,
        ])->title("Commission · {$this->booking->booking_number}");
    }
}
