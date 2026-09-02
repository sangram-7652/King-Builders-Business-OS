<?php

declare(strict_types=1);

namespace App\Actions\Possession;

use App\Actions\Documents\UploadDocumentAction;
use App\Enums\PossessionActivityType;
use App\Enums\PossessionCaseStatus;
use App\Exceptions\DomainException;
use App\Models\Document;
use App\Models\Masters\DocumentType;
use App\Models\PossessionCase;
use App\Models\User;
use App\Services\Possession\PossessionCertificateService;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Possession\PossessionTimeline;
use Illuminate\Support\Facades\Log;

/**
 * Generates the possession certificate (M10). `possession.complete`. Uses the
 * shared M9 document architecture — the certificate is a versioned
 * POSSESSION_CERTIFICATE document on the booking. Idempotent: a case that
 * already has a certificate returns it unchanged.
 */
class GeneratePossessionCertificateAction
{
    use RunsInTransaction;

    public function __construct(
        private readonly PossessionCertificateService $pdf,
        private readonly UploadDocumentAction $upload,
    ) {}

    public function handle(PossessionCase $case, User $actor): Document
    {
        if (! $actor->can('possession.complete')) {
            throw new DomainException('You are not authorised to generate the possession certificate.');
        }

        return $this->transaction(function () use ($case, $actor): Document {
            /** @var PossessionCase $locked */
            $locked = PossessionCase::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== PossessionCaseStatus::Completed) {
                throw new DomainException('The possession certificate can only be generated once possession is completed.');
            }

            if ($locked->certificate_document_id !== null) {
                return Document::query()->findOrFail($locked->certificate_document_id);
            }

            $locked->load(['booking.project', 'booking.block', 'booking.plot', 'booking.bookingBuyers.buyer', 'handover', 'assignedTo']);

            $type = DocumentType::query()->where('code', 'POSSESSION_CERTIFICATE')->firstOrFail();
            $document = $this->upload->handle(
                $locked->booking,
                $type,
                $this->pdf->renderAsUpload($locked),
                $actor,
                ['title' => "Possession certificate — {$locked->case_number}"],
            );

            $locked->forceFill([
                'certificate_document_id' => $document->id,
                'certificate_generated_at' => now(),
            ])->save();

            PossessionTimeline::record(
                PossessionActivityType::CertificateGenerated,
                "Possession certificate generated for {$locked->case_number}.",
                $locked->booking, $locked->booking->plot, null,
                ['possession_case_id' => $locked->id, 'document_id' => $document->id],
                $actor,
            );

            Log::info('possession_certificate.generated', ['possession_case_id' => $locked->id, 'document_id' => $document->id, 'by' => $actor->id]);

            return $document->load('versions');
        });
    }
}
