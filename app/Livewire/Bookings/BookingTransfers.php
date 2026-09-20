<?php

declare(strict_types=1);

namespace App\Livewire\Bookings;

use App\Actions\Transfer\CompleteTransferAction;
use App\Actions\Transfer\CreateTransferRequestAction;
use App\Actions\Transfer\TransferWorkflowAction;
use App\Enums\PlotStatus;
use App\Enums\TransferType;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\Buyer;
use App\Models\Plot;
use App\Models\TransferRequest;
use App\Services\Ownership\PlotOwnershipService;
use App\Services\Transfer\TransferEligibilityService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class BookingTransfers extends Component
{
    public Booking $booking;

    public bool $showCreate = false;

    public string $transferType = 'sale_transfer';

    public string $newBuyerId = '';

    public string $newPlotId = '';

    public string $reason = '';

    public ?int $reviewingId = null;

    public string $rejectReason = '';

    public string $waiverReason = '';

    public function mount(Booking $booking): void
    {
        $this->authorize('viewAny', TransferRequest::class);
        $this->authorize('view', $booking);
        abort_unless($booking->isConfirmed(), 404);
        $this->booking = $booking->loadMissing('plot:id,plot_number,project_id,block_id');
    }

    private function find(int $id): TransferRequest
    {
        return $this->booking->transferRequests()->whereKey($id)->firstOrFail();
    }

    private function run(callable $fn, string $ok): void
    {
        try {
            $fn();
            $this->dispatch('toast', message: $ok, variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function create(): void
    {
        $this->authorize('create', TransferRequest::class);
        $type = TransferType::from($this->transferType);
        $this->validate([
            'newBuyerId' => [$type->movesOwnership() ? 'required' : 'nullable', 'integer', 'exists:buyers,id'],
            'newPlotId' => [$type->movesPlot() ? 'required' : 'nullable', 'integer', 'exists:plots,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $this->run(function () use ($type): void {
            app(CreateTransferRequestAction::class)->handle($this->booking, $type, [
                'new_buyer_id' => $this->newBuyerId !== '' ? (int) $this->newBuyerId : null,
                'new_plot_id' => $this->newPlotId !== '' ? (int) $this->newPlotId : null,
                'reason' => $this->reason ?: null,
            ], auth()->user());
            $this->reset('showCreate', 'newBuyerId', 'newPlotId', 'reason');
        }, 'Transfer request created.');
    }

    public function submit(int $id): void
    {
        $t = $this->find($id);
        $this->authorize('update', $t);
        $this->run(fn () => app(TransferWorkflowAction::class)->submit($t, auth()->user()), 'Transfer submitted.');
    }

    public function startReview(int $id): void
    {
        $t = $this->find($id);
        $this->authorize('review', $t);
        $this->run(fn () => app(TransferWorkflowAction::class)->startReview($t, auth()->user()), 'Review started.');
    }

    public function requestDocuments(int $id): void
    {
        $t = $this->find($id);
        $this->authorize('review', $t);
        $this->run(fn () => app(TransferWorkflowAction::class)->requestDocuments($t, auth()->user()), 'Marked documents pending.');
    }

    public function backToReview(int $id): void
    {
        $t = $this->find($id);
        $this->authorize('review', $t);
        $this->run(fn () => app(TransferWorkflowAction::class)->backToReview($t, auth()->user()), 'Back under review.');
    }

    public function approve(int $id): void
    {
        $t = $this->find($id);
        $this->authorize('approve', $t);
        $this->run(function () use ($t): void {
            app(TransferWorkflowAction::class)->approve($t, auth()->user(), ['financial_waiver_reason' => $this->waiverReason ?: null]);
            $this->reset('waiverReason', 'reviewingId');
        }, 'Transfer approved.');
    }

    public function reject(int $id): void
    {
        $t = $this->find($id);
        $this->authorize('review', $t);
        $this->validate(['rejectReason' => ['required', 'string', 'min:3', 'max:255']]);
        $this->run(function () use ($t): void {
            app(TransferWorkflowAction::class)->reject($t, $this->rejectReason, auth()->user());
            $this->reset('rejectReason', 'reviewingId');
        }, 'Transfer rejected.');
    }

    public function complete(int $id): void
    {
        $t = $this->find($id);
        $this->authorize('complete', $t);
        $this->run(fn () => app(CompleteTransferAction::class)->handle($t, auth()->user()), 'Transfer completed — ownership updated.');
    }

    public function cancelRequest(int $id): void
    {
        $t = $this->find($id);
        $this->authorize('update', $t);
        $this->run(fn () => app(TransferWorkflowAction::class)->cancel($t, 'Cancelled from transfer screen', auth()->user()), 'Transfer cancelled.');
    }

    public function render(): View
    {
        $transfers = $this->booking->transferRequests()
            ->with([
                'currentBuyer:id,first_name,middle_name,last_name,customer_code', 'newBuyer:id,first_name,middle_name,last_name,customer_code',
                'plot:id,plot_number', 'newPlot:id,plot_number', 'approvedBy:id,name',
            ])
            ->get();

        $eligibilityByTransfer = $transfers->mapWithKeys(fn (TransferRequest $t) => [
            $t->id => app(TransferEligibilityService::class)->evaluate($t),
        ]);

        $owners = app(PlotOwnershipService::class)->currentOwners($this->booking);

        // Plot-transfer target picker: active, available/held plots in the
        // SAME project as the booking's current plot, excluding it.
        $plots = Plot::query()
            ->where('project_id', $this->booking->project_id)
            ->where('is_active', true)
            ->whereIn('status', [PlotStatus::Available->value, PlotStatus::Hold->value])
            ->whereKeyNot($this->booking->plot_id)
            ->orderBy('plot_number')
            ->get(['id', 'plot_number', 'block_id']);

        return view('livewire.bookings.booking-transfers', [
            'booking' => $this->booking,
            'transfers' => $transfers,
            'eligibilityByTransfer' => $eligibilityByTransfer,
            'owners' => $owners->load('buyer:id,first_name,middle_name,last_name,customer_code'),
            'transferTypes' => TransferType::options(),
            'buyers' => Buyer::query()->where('status', 'active')->orderBy('first_name')->get(['id', 'first_name', 'middle_name', 'last_name', 'customer_code']),
            'plots' => $plots->mapWithKeys(fn (Plot $p) => [$p->id => "Plot {$p->plot_number}"]),
        ])->title("Transfers · {$this->booking->booking_number}");
    }
}
