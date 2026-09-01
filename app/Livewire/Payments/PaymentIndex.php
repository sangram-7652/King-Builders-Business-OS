<?php

declare(strict_types=1);

namespace App\Livewire\Payments;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Payments')]
class PaymentIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    public int $perPage = 20;

    public function mount(): void
    {
        $this->authorize('viewAny', Payment::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status');
        $this->resetPage();
    }

    /** @return Builder<Payment> */
    protected function query(): Builder
    {
        return Payment::query()
            ->with(['booking:id,booking_number', 'paymentMode:id,name', 'receipt:id,payment_id,receipt_number'])
            ->search($this->search)
            ->status($this->status)
            ->orderByDesc('id');
    }

    public function render(): View
    {
        return view('livewire.payments.payment-index', [
            'payments' => $this->query()->paginate($this->perPage),
            'statuses' => PaymentStatus::options(),
        ]);
    }
}
