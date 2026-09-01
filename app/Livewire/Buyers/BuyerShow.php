<?php

declare(strict_types=1);

namespace App\Livewire\Buyers;

use App\Actions\Buyers\ChangeBuyerStatus;
use App\Enums\BuyerStatus;
use App\Exceptions\DomainException;
use App\Models\Buyer;
use App\Services\Collections\BuyerCollectionProfile;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class BuyerShow extends Component
{
    public Buyer $buyer;

    public bool $revealDocuments = false;

    public function mount(Buyer $buyer): void
    {
        $this->authorize('view', $buyer);
        $this->buyer = $buyer->load(['state', 'city', 'createdBy', 'leads']);
    }

    public function toggleReveal(): void
    {
        // Full PAN / Aadhaar require the dedicated permission — always re-checked.
        $this->authorize('viewDocuments', $this->buyer);
        $this->revealDocuments = ! $this->revealDocuments;
    }

    public function changeStatus(string $status): void
    {
        $this->authorize('changeStatus', $this->buyer);

        $target = BuyerStatus::tryFrom($status);
        if ($target === null) {
            return;
        }

        try {
            app(ChangeBuyerStatus::class)->handle($this->buyer, $target);
            $this->buyer = $this->buyer->fresh(['state', 'city', 'createdBy', 'leads']);
            $this->dispatch('toast', message: "Buyer {$target->label()}.", variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function render(): View
    {
        $canViewDocuments = auth()->user()->can('viewDocuments', $this->buyer);

        return view('livewire.buyers.buyer-show', [
            'canViewDocuments' => $canViewDocuments,
            'pan' => $this->displaySensitive('pan_number', $canViewDocuments),
            'aadhaar' => $this->displaySensitive('aadhaar_number', $canViewDocuments),
            'allowedStatuses' => $this->buyer->status->allowedTransitions(),
            'collectionProfile' => auth()->user()->can('collections.view')
                ? app(BuyerCollectionProfile::class)->for($this->buyer)
                : null,
        ])->title($this->buyer->fullName());
    }

    private function displaySensitive(string $field, bool $canView): ?string
    {
        $raw = $this->buyer->{$field};

        if ($raw === null || $raw === '') {
            return null;
        }

        if ($this->revealDocuments && $canView) {
            return $field === 'pan_number' ? $this->buyer->pan_number : $this->buyer->aadhaar_number;
        }

        return $field === 'pan_number' ? $this->buyer->maskedPan() : $this->buyer->maskedAadhaar();
    }
}
