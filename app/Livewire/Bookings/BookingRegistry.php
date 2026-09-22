<?php

declare(strict_types=1);

namespace App\Livewire\Bookings;

use App\Actions\Registry\MarkRegistryDoneAction;
use App\Enums\DocumentActivityType;
use App\Enums\Permission;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\DocumentActivity;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Registry (simplified). Per the client's product requirement, Registry is
 * nothing more than a booking-level status — PENDING or DONE — with no
 * Registry Case, no eligibility gate (no collection percentage, no document
 * verification, no agreement check) and no "Open registry case" workflow.
 *
 * The OLD Registry Case / RegistryEligibilityService / appointment / expense /
 * document-handover workflow (previously exposed on this exact screen) has
 * been intentionally removed from the UI, NOT deleted: RegistryCase and
 * everything that hung off it (RegistryCaseWorkflowAction, RegistryAppointment,
 * RegistryExpense, DocumentHandover, RegistryEligibilityService) is kept
 * intact for any booking that already has historical data there. Nothing new
 * is ever written to those tables from this screen — see
 * {@see MarkRegistryDoneAction}, the ONLY write path
 * for the new Registry status.
 */
#[Layout('components.layouts.app')]
class BookingRegistry extends Component
{
    public Booking $booking;

    public function mount(Booking $booking): void
    {
        abort_unless(auth()->user()->can(Permission::RegistryView->value), 403);
        $this->authorize('view', $booking);
        abort_unless($booking->isConfirmed(), 404);
        // Route-model-binding always yields every current column in real
        // usage; `refresh()` here only matters for the (rare) case of a
        // caller holding a partially-hydrated instance, and keeps `$this->
        // booking` — the SAME instance Livewire exposes to the view — in
        // sync so `render()` never needs a second, separately-fetched copy.
        $booking->refresh();
        $this->booking = $booking;
    }

    public function markDone(): void
    {
        try {
            app(MarkRegistryDoneAction::class)->handle($this->booking, auth()->user());
            $this->booking->refresh();
            $this->dispatch('toast', message: 'Registry marked Done. Plot is now Sold.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function render(): View
    {
        $this->booking->loadMissing(['project', 'plot']);

        $history = DocumentActivity::query()
            ->where('booking_id', $this->booking->id)
            ->where('type', DocumentActivityType::RegistryStatusChanged)
            ->with('causer')
            ->latest('id')
            ->get();

        return view('livewire.bookings.booking-registry', [
            'history' => $history,
        ])->title("Registry · {$this->booking->booking_number}");
    }
}
