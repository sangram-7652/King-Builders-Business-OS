<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Documents;

use App\Livewire\Portal\Concerns\InteractsWithCustomer;
use App\Models\Booking;
use App\Models\Document;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * "My documents" (M15.2) — every document that belongs to the signed-in
 * customer directly (their own KYC) or to a booking they co-own. Reuses the
 * exact M9 `Document`/`DocumentVersion` pipeline; nothing is re-queried or
 * re-modelled here. Mirrors the same scoping {@see CustomerPortfolioService}
 * already uses for the dashboard's "documents pending" tile, so the two
 * numbers can never drift.
 */
#[Layout('components.layouts.portal')]
#[Title('My documents')]
class Index extends Component
{
    use InteractsWithCustomer;

    public function render(): View
    {
        $customer = $this->customer();
        $bookingIds = $customer->bookingBuyers()->pluck('booking_id');

        $documents = Document::query()
            ->where(function ($q) use ($customer, $bookingIds): void {
                $q->where(fn ($w) => $w->where('documentable_type', $customer->getMorphClass())->where('documentable_id', $customer->id))
                    ->orWhere(fn ($w) => $w->where('documentable_type', (new Booking)->getMorphClass())->whereIn('documentable_id', $bookingIds));
            })
            ->with(['documentType', 'currentVersion', 'documentable'])
            ->orderByDesc('id')
            ->get();

        return view('livewire.portal.documents.index', ['documents' => $documents]);
    }
}
