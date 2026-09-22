<?php

declare(strict_types=1);

namespace App\Livewire\Bookings;

use App\Actions\Transfer\ExecutePlotTransferAction;
use App\Enums\Permission;
use App\Enums\PlotStatus;
use App\Enums\TransferRequestStatus;
use App\Enums\TransferType;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\Plot;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Transfers (simplified). Per the client's product requirement, the ONLY
 * transfer feature is a PLOT TRANSFER — moving a confirmed booking to a
 * different, available plot in the same project. The buyer never changes,
 * the booking is never recreated.
 *
 * The OLD ownership / nominee transfer workflow (Owner Change, Family
 * Transfer, Sale Transfer, Legal Transfer, Nominee Change — a new-buyer
 * picker, a multi-step Draft/Submitted/UnderReview/Approved review, transfer
 * documents, and a "current owners" ledger view) has been intentionally
 * removed from THIS screen, NOT deleted: `TransferRequest`,
 * `TransferWorkflowAction`, `CreateTransferRequestAction`,
 * `CompleteTransferAction`, `PlotOwnershipService::applyTransfer()` and the
 * transfer-document types are all kept intact for any booking that already
 * has historical data there. Nothing new is ever created through that path
 * from this screen — see {@see ExecutePlotTransferAction}, the ONLY write
 * path for a new transfer here, which goes straight to COMPLETED in one
 * atomic step (every plot-transfer eligibility check is structural — there
 * is no human review step left to expose).
 */
#[Layout('components.layouts.app')]
class BookingTransfers extends Component
{
    public Booking $booking;

    public bool $showTransfer = false;

    public string $newPlotId = '';

    public string $reason = '';

    public function mount(Booking $booking): void
    {
        abort_unless(auth()->user()->can(Permission::TransferView->value), 403);
        $this->authorize('view', $booking);
        abort_unless($booking->isConfirmed(), 404);
        $booking->refresh();
        $this->booking = $booking;
    }

    public function openTransfer(): void
    {
        abort_unless(auth()->user()->can(Permission::TransferComplete->value), 403);
        $this->reset('newPlotId', 'reason');
        $this->showTransfer = true;
    }

    public function transferPlot(): void
    {
        $this->validate([
            'newPlotId' => ['required', 'integer', 'exists:plots,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            app(ExecutePlotTransferAction::class)->handle(
                $this->booking,
                (int) $this->newPlotId,
                $this->reason ?: null,
                auth()->user(),
            );
            $this->booking->refresh();
            $this->reset('showTransfer', 'newPlotId', 'reason');
            $this->dispatch('toast', message: 'Plot transferred.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function render(): View
    {
        $this->booking->loadMissing(['project', 'block', 'plot']);

        $history = $this->booking->transferRequests()
            ->where('transfer_type', TransferType::PlotTransfer->value)
            ->where('status', TransferRequestStatus::Completed->value)
            ->with(['plot:id,plot_number,block_id', 'newPlot:id,plot_number,block_id', 'completedBy:id,name'])
            ->orderByDesc('completed_at')
            ->get();

        // Target picker: active, AVAILABLE plots in the SAME project as the
        // booking's current plot, excluding the current plot itself.
        $availablePlots = Plot::query()
            ->where('project_id', $this->booking->project_id)
            ->where('is_active', true)
            ->where('status', PlotStatus::Available->value)
            ->whereKeyNot($this->booking->plot_id)
            ->orderBy('plot_number')
            ->get(['id', 'plot_number', 'block_id']);

        return view('livewire.bookings.booking-transfers', [
            'history' => $history,
            'availablePlots' => $availablePlots->mapWithKeys(fn (Plot $p) => [$p->id => "Plot {$p->plot_number}"]),
        ])->title("Transfers · {$this->booking->booking_number}");
    }
}
