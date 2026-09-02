<?php

declare(strict_types=1);

namespace App\Livewire\Transfer;

use App\Enums\TransferRequestStatus;
use App\Models\TransferRequest;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Transfers')]
class TransferDashboard extends Component
{
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', TransferRequest::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $counts = TransferRequest::query()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $transfers = TransferRequest::query()
            ->with([
                'booking:id,booking_number,project_id', 'booking.project:id,name',
                'plot:id,plot_number', 'currentBuyer:id,first_name,middle_name,last_name', 'newBuyer:id,first_name,middle_name,last_name',
            ])
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->search !== '', fn ($q) => $q->where('request_number', 'like', "%{$this->search}%")
                ->orWhereHas('booking', fn ($b) => $b->where('booking_number', 'like', "%{$this->search}%")))
            ->orderByRaw("case status
                when 'approved' then 0 when 'under_review' then 1 when 'documents_pending' then 2
                when 'submitted' then 3 when 'draft' then 4 else 5 end")
            ->latest('id')
            ->paginate(20);

        return view('livewire.transfer.transfer-dashboard', [
            'counts' => $counts,
            'transfers' => $transfers,
            'statuses' => TransferRequestStatus::options(),
        ]);
    }
}
