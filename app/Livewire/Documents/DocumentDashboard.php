<?php

declare(strict_types=1);

namespace App\Livewire\Documents;

use App\Enums\DocumentStatus;
use App\Models\Document;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Documents')]
class DocumentDashboard extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', Document::class);
    }

    public function render(): View
    {
        $counts = Document::query()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $pendingVerification = Document::query()
            ->whereIn('status', [DocumentStatus::Uploaded->value, DocumentStatus::UnderReview->value])
            ->with(['documentType', 'documentable'])
            ->latest('updated_at')
            ->limit(20)
            ->get();

        $rejected = Document::query()
            ->whereIn('status', [DocumentStatus::Rejected->value, DocumentStatus::Expired->value])
            ->with(['documentType', 'documentable'])
            ->latest('updated_at')
            ->limit(20)
            ->get();

        return view('livewire.documents.document-dashboard', [
            'counts' => $counts,
            'pendingVerification' => $pendingVerification,
            'rejected' => $rejected,
        ]);
    }
}
