<?php

declare(strict_types=1);

namespace App\Livewire\Buyers;

use App\Livewire\Documents\ManagesDocumentSlots;
use App\Models\Buyer;
use App\Models\Document;
use App\Services\Documents\DocumentChecklistService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class BuyerDocuments extends Component
{
    use ManagesDocumentSlots;

    public Buyer $buyer;

    public function mount(Buyer $buyer): void
    {
        $this->authorize('view', $buyer);
        abort_unless(auth()->user()->can('documents.view'), 403);
        $this->buyer = $buyer;
    }

    protected function documentable(): Model
    {
        return $this->buyer;
    }

    public function render(): View
    {
        $checklist = app(DocumentChecklistService::class)->forBuyer($this->buyer);

        $documents = Document::query()
            ->where('documentable_type', $this->buyer->getMorphClass())
            ->where('documentable_id', $this->buyer->id)
            ->with(['documentType', 'currentVersion', 'verifiedBy', 'versions'])
            ->get()
            ->keyBy('document_type_id');

        return view('livewire.buyers.buyer-documents', [
            'checklist' => $checklist,
            'documents' => $documents,
        ])->title("Documents · {$this->buyer->fullName()}");
    }
}
