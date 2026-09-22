<?php

declare(strict_types=1);

namespace App\Livewire\Bookings;

use App\Actions\Possession\MarkPossessionDoneAction;
use App\Enums\Permission;
use App\Enums\PossessionActivityType;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\PossessionActivity;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Possession (simplified). Per the client's product requirement, Possession
 * is nothing more than a booking-level status — PENDING or DONE — with no
 * Possession Case, no eligibility gate (no collection percentage, no
 * document verification, no Registry dependency) and no "Open possession
 * case" workflow.
 *
 * The OLD Possession Case / PossessionEligibilityService / clearance /
 * appointment / inspection / handover / certificate workflow (previously
 * exposed on this exact screen) has been intentionally removed from the UI,
 * NOT deleted: PossessionCase and everything that hung off it
 * (PossessionCaseWorkflowAction, PossessionClearance, PossessionAppointment,
 * PossessionInspection, PossessionHandover, GeneratePossessionCertificateAction,
 * PossessionEligibilityService) is kept intact for any booking that already
 * has historical data there. Nothing new is ever written to those tables
 * from this screen — see {@see MarkPossessionDoneAction}, the ONLY write
 * path for the new Possession status.
 *
 * Marking Possession Done NEVER changes the plot's status — see the
 * docblock on that action.
 */
#[Layout('components.layouts.app')]
class BookingPossession extends Component
{
    public Booking $booking;

    public function mount(Booking $booking): void
    {
        abort_unless(auth()->user()->can(Permission::PossessionView->value), 403);
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
            app(MarkPossessionDoneAction::class)->handle($this->booking, auth()->user());
            $this->booking->refresh();
            $this->dispatch('toast', message: 'Possession marked Done.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function render(): View
    {
        $this->booking->loadMissing(['project', 'plot']);

        $history = PossessionActivity::query()
            ->where('booking_id', $this->booking->id)
            ->where('type', PossessionActivityType::PossessionStatusChanged)
            ->with('causer')
            ->latest('id')
            ->get();

        return view('livewire.bookings.booking-possession', [
            'history' => $history,
        ])->title("Possession · {$this->booking->booking_number}");
    }
}
