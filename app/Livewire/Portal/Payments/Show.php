<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Payments;

use App\Livewire\Portal\Concerns\ResolvesPortalRecords;
use App\Models\Payment;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.portal')]
class Show extends Component
{
    use ResolvesPortalRecords;

    public Payment $payment;

    public function mount(Payment $payment): void
    {
        // Ownership + SUCCESS-only scope; a miss is a 404.
        $this->payment = $this->paymentOr404($payment->id);
    }

    public function render(): View
    {
        return view('livewire.portal.payments.show', [
            'payment' => $this->payment,
            'receipt' => $this->payment->receipt && $this->payment->receipt->voided_at === null
                ? $this->payment->receipt
                : null,
        ])->title($this->payment->payment_number);
    }
}
