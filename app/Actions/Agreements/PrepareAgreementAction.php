<?php

declare(strict_types=1);

namespace App\Actions\Agreements;

use App\Actions\Documents\UploadDocumentAction;
use App\Enums\AgreementStatus;
use App\Enums\DocumentActivityType;
use App\Exceptions\DomainException;
use App\Models\Agreement;
use App\Models\Masters\DocumentType;
use App\Models\User;
use App\Services\Documents\AgreementPdfService;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Documents\DocumentTimeline;
use Illuminate\Support\Facades\Log;

/**
 * DRAFT → PREPARED (M9). Validates the booking still has the information the
 * agreement needs, FREEZES the M6 booking terms into `terms_snapshot` (read
 * only — the price snapshot itself is never touched), and generates a PDF as
 * the first version of the agreement document.
 *
 * Re-preparing a PREPARED agreement regenerates the PDF as a NEW version (the
 * old one is kept).
 */
class PrepareAgreementAction
{
    use RunsInTransaction;

    public function __construct(
        private readonly AgreementPdfService $pdf,
        private readonly UploadDocumentAction $upload,
    ) {}

    public function handle(Agreement $agreement, User $actor): Agreement
    {
        return $this->transaction(function () use ($agreement, $actor): Agreement {
            /** @var Agreement $locked */
            $locked = Agreement::query()->whereKey($agreement->getKey())->lockForUpdate()->firstOrFail();
            $locked->load(['booking.bookingBuyers', 'booking.plot']);

            if (! in_array($locked->status, [AgreementStatus::Draft, AgreementStatus::Prepared], true)) {
                throw new DomainException("A {$locked->status->label()} agreement cannot be (re-)prepared.");
            }

            $booking = $locked->booking;

            if (! $booking->isConfirmed()) {
                throw new DomainException('The booking is no longer confirmed.');
            }

            if ($booking->bookingBuyers->isEmpty()) {
                throw new DomainException('The booking has no buyers.');
            }

            if ($booking->plot === null) {
                throw new DomainException('The booking has no plot.');
            }

            $locked->forceFill([
                'status' => AgreementStatus::Prepared,
                'prepared_at' => now(),
                'prepared_by' => $actor->id,
                'terms_snapshot' => [
                    'frozen_at' => now()->toIso8601String(),
                    'booking_number' => $booking->booking_number,
                    'base_amount' => $booking->base_amount,
                    'plc_amount' => $booking->plc_amount,
                    'charge_amount' => $booking->charge_amount,
                    'discount_amount' => $booking->discount_amount,
                    'tax_amount' => $booking->tax_amount,
                    'final_amount' => $booking->final_amount,
                    'pricing_snapshot_ref' => $booking->pricing_snapshot ? 'booking' : null,
                ],
            ])->save();

            $type = DocumentType::query()->where('code', 'BOOKING_AGREEMENT')->firstOrFail();
            $document = $this->upload->handle(
                $booking,
                $type,
                $this->pdf->renderAsUpload($locked->fresh(['booking.project', 'booking.block', 'booking.plot', 'booking.bookingBuyers.buyer', 'preparedBy'])),
                $actor,
                ['title' => "Agreement {$locked->agreement_number}"],
            );
            $locked->forceFill(['document_id' => $document->id])->save();

            DocumentTimeline::record(
                DocumentActivityType::AgreementPrepared,
                "Agreement {$locked->agreement_number} prepared.",
                $booking, null, ['agreement_id' => $locked->id], $actor,
            );

            Log::info('agreement.prepared', ['agreement_id' => $locked->id, 'by' => $actor->id]);

            return $locked->load('document.versions');
        });
    }
}
