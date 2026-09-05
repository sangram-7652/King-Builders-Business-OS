<?php

declare(strict_types=1);

namespace App\Livewire\Partners;

use App\Enums\PartnerActivityType;
use App\Livewire\Documents\ManagesDocumentSlots;
use App\Models\Document;
use App\Models\Partner;
use App\Services\Documents\DocumentChecklistService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class PartnerDocuments extends Component
{
    use ManagesDocumentSlots;

    public Partner $partner;

    public function mount(Partner $partner): void
    {
        $this->authorize('view', $partner);
        abort_unless(auth()->user()->can('documents.view'), 403);
        $this->partner = $partner;
    }

    protected function documentable(): Model
    {
        return $this->partner;
    }

    /** Mirror each KYC document change onto the partner's own audit timeline. */
    protected function afterDocumentMutation(string $event, ?Document $document = null): void
    {
        $type = match ($event) {
            'uploaded' => PartnerActivityType::KycUploaded,
            'verified' => PartnerActivityType::KycVerified,
            'rejected' => PartnerActivityType::KycRejected,
            default => null,
        };

        if ($type === null) {
            return;
        }

        $label = $document?->loadMissing('documentType')->documentType?->name ?? 'KYC document';

        $this->partner->recordActivity($type, "{$label} {$event}.", [
            'document_id' => $document?->id,
        ], auth()->user());
    }

    public function render(): View
    {
        $documents = Document::query()
            ->where('documentable_type', $this->partner->getMorphClass())
            ->where('documentable_id', $this->partner->id)
            ->with(['documentType', 'currentVersion', 'verifiedBy', 'versions'])
            ->get()
            ->keyBy('document_type_id');

        return view('livewire.partners.partner-documents', [
            'checklist' => app(DocumentChecklistService::class)->forPartner($this->partner),
            'documents' => $documents,
        ])->title("KYC · {$this->partner->displayName()}");
    }
}
