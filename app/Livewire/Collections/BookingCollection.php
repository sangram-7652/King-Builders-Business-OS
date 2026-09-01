<?php

declare(strict_types=1);

namespace App\Livewire\Collections;

use App\Actions\Collections\EnsureCollectionCaseAction;
use App\Enums\InstallmentStatus;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\CollectionCase;
use App\Services\Collections\AgingCalculator;
use App\Services\Payments\PaymentLedger;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The collection tab for a booking (M8) — reads M7 truth, links to the case.
 */
#[Layout('components.layouts.app')]
class BookingCollection extends Component
{
    public Booking $booking;

    public function mount(Booking $booking): void
    {
        $this->authorize('viewAny', CollectionCase::class);
        abort_unless($booking->isConfirmed(), 404);
        $this->booking = $booking;
    }

    public function openCase(): void
    {
        $this->authorize('create', CollectionCase::class);

        try {
            app(EnsureCollectionCaseAction::class)->handle($this->booking, auth()->user());
            $this->dispatch('toast', message: 'Collection case opened.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function render(): View
    {
        $booking = $this->booking->fresh([
            'project', 'plot', 'activePaymentPlan.installments',
            'collectionCase.assignedTo', 'collectionCase.followUps', 'collectionCase.promises', 'collectionCase.activities.causer',
        ]);
        $ledger = app(PaymentLedger::class);
        $aging = app(AgingCalculator::class);

        $installments = $booking->activePaymentPlan?->installments->map(fn ($i) => [
            'model' => $i,
            'paid' => $ledger->installmentPaid($i),
            'outstanding' => $ledger->installmentOutstanding($i),
            'days_overdue' => $i->status === InstallmentStatus::Waived ? 0 : $aging->daysOverdue($i),
        ]) ?? collect();

        return view('livewire.collections.booking-collection', [
            'booking' => $booking,
            'summary' => $ledger->summary($booking),
            'installments' => $installments,
            'case' => $booking->collectionCase,
        ])->title("Collection · {$booking->booking_number}");
    }
}
