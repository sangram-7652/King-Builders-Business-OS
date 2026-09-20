<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Enums\DocumentScope;
use App\Enums\DocumentStatus;
use App\Models\Booking;
use App\Models\Buyer;
use App\Models\Document;
use App\Models\DocumentRequirement;
use App\Models\Masters\DocumentType;
use App\Models\Partner;
use App\Support\Documents\ChecklistResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Derives the document checklist for a buyer (KYC) or a booking (M9).
 *
 * Requirements resolve as: a project-specific `document_requirements` row wins
 * over a global one; if there is no requirement row at all, a document type
 * with `default_required = true` still counts as required. Nothing is
 * hard-coded in Blade and no completion percentage is stored.
 */
class DocumentChecklistService
{
    public function forBuyer(Buyer $buyer): ChecklistResult
    {
        return $this->build($buyer, DocumentScope::Buyer, null);
    }

    public function forBooking(Booking $booking): ChecklistResult
    {
        return $this->build($booking, DocumentScope::Booking, $booking->project_id);
    }

    public function forPartner(Partner $partner): ChecklistResult
    {
        return $this->build($partner, DocumentScope::Partner, null);
    }

    private function build(Model $documentable, DocumentScope $scope, ?int $projectId): ChecklistResult
    {
        /** @var Collection<int, DocumentType> $types */
        $types = DocumentType::query()
            ->where('applies_to', $scope->value)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        /** @var Collection<int, DocumentRequirement> $requirements */
        $requirements = DocumentRequirement::query()
            ->where('applies_to', $scope->value)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('project_id')->when($projectId, fn ($q) => $q->orWhere('project_id', $projectId)))
            ->get()
            ->sortByDesc('project_id') // project-specific first
            ->groupBy('document_type_id');

        /** @var Collection<int, Document> $documents */
        $documents = $documentable->documents()->get()->keyBy('document_type_id');

        $items = [];
        $required = $received = $verified = $rejected = $pending = 0;

        foreach ($types as $type) {
            $rule = $requirements->get($type->id)?->first();
            $isRequired = $rule?->required ?? $type->default_required;
            $sequence = $rule?->sequence ?? $type->sort_order;

            $doc = $documents->get($type->id);
            $status = $doc?->status ?? DocumentStatus::Pending;
            $hasFile = $doc?->hasFile() ?? false;
            $missing = $isRequired && ! ($status === DocumentStatus::Verified);

            if (! $isRequired && $doc === null) {
                // Optional and never touched — still list it so it can be uploaded.
            }

            if ($isRequired) {
                $required++;
            }
            // Received/verified/rejected/pending reflect every document actually on
            // file (required or optional) — an untouched, never-uploaded optional
            // slot is not "pending" (nothing is expected of it).
            if ($hasFile) {
                $received++;
                match ($status) {
                    DocumentStatus::Verified => $verified++,
                    DocumentStatus::Rejected, DocumentStatus::Expired => $rejected++,
                    default => $pending++, // Uploaded / UnderReview: awaiting a decision.
                };
            }

            $items[] = [
                'document_type_id' => $type->id,
                'name' => $type->name,
                'required' => (bool) $isRequired,
                'sequence' => (int) $sequence,
                'document_id' => $doc?->id,
                'status' => $status->value,
                'missing' => $missing,
            ];
        }

        usort($items, fn ($a, $b) => [$b['required'], $a['sequence']] <=> [$a['required'], $b['sequence']]);

        return new ChecklistResult(
            items: $items,
            requiredCount: $required,
            receivedCount: $received,
            verifiedCount: $verified,
            rejectedCount: $rejected,
            pendingCount: $pending,
        );
    }
}
