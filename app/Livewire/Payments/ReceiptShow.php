<?php

declare(strict_types=1);

namespace App\Livewire\Payments;

use App\Models\Receipt;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class ReceiptShow extends Component
{
    public Receipt $receipt;

    public function mount(Receipt $receipt): void
    {
        $this->authorize('view', $receipt);
        $this->receipt = $receipt->load(['payment.paymentMode', 'booking.project', 'booking.plot', 'buyer', 'issuedBy']);
    }

    public function render(): View
    {
        return view('livewire.payments.receipt-show')->title($this->receipt->receipt_number);
    }
}
