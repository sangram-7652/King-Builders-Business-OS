<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Actions\Agreements\PrepareAgreementAction;
use App\Actions\Documents\UploadDocumentAction;
use App\Actions\Possession\GeneratePossessionCertificateAction;
use App\Enums\DocumentActivityType;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\BookingWitness;
use App\Models\Document;
use App\Models\Masters\DocumentType;
use App\Models\User;
use App\Services\Documents\PlotKycReceiptPdfService;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Documents\DocumentTimeline;
use Illuminate\Support\Facades\Log;

/**
 * Generates the Plot KYC / Registry KYC Receipt for a confirmed booking.
 * Reuses the exact M9 architecture already proven by
 * {@see PrepareAgreementAction} and
 * {@see GeneratePossessionCertificateAction}: the
 * receipt is a versioned `PLOT_KYC_RECEIPT` document on the booking, stored
 * through {@see UploadDocumentAction} — no second document or payment system.
 *
 * Optionally accepts the transaction-specific details this receipt needs that
 * nothing else in the system captures (Vikray Muly declared value, up to two
 * witnesses) and persists them on the booking before rendering. Both are
 * optional — omitting or blanking them never blocks generation.
 *
 * Calling this again on an already-generated receipt does not create a new
 * `Document` row — it appends a new `DocumentVersion` to the existing one
 * (old versions are kept; see {@see UploadDocumentAction}), the same
 * re-prepare semantics as the Agreement.
 */
class GeneratePlotKycReceiptAction
{
    use RunsInTransaction;

    public function __construct(
        private readonly PlotKycReceiptPdfService $pdf,
        private readonly UploadDocumentAction $upload,
    ) {}

    /**
     * @param  array{vikray_muly_amount?: string|null, witnesses?: list<array{name?: string|null, address?: string|null, mobile?: string|null}>}  $details  already-validated
     */
    public function handle(Booking $booking, User $actor, array $details = []): Document
    {
        return $this->transaction(function () use ($booking, $actor, $details): Document {
            /** @var Booking $locked */
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();
            $locked->load(['plot', 'bookingBuyers']);

            if (! $locked->isConfirmed()) {
                throw new DomainException('The Plot KYC Receipt can only be generated for a confirmed booking.');
            }

            if ($locked->plot === null) {
                throw new DomainException('The booking has no plot.');
            }

            if ($locked->bookingBuyers->isEmpty()) {
                throw new DomainException('The booking has no buyers.');
            }

            if (array_key_exists('vikray_muly_amount', $details)) {
                $locked->forceFill(['vikray_muly_amount' => $details['vikray_muly_amount'] ?: null])->save();
            }

            if (array_key_exists('witnesses', $details)) {
                $this->syncWitnesses($locked, $details['witnesses']);
            }

            $type = DocumentType::query()->where('code', 'PLOT_KYC_RECEIPT')->firstOrFail();

            $fresh = $locked->fresh([
                'project', 'block', 'plot.dimension',
                'bookingBuyers.buyer.city', 'witnesses', 'payments',
            ]);

            $document = $this->upload->handle(
                $locked,
                $type,
                $this->pdf->renderAsUpload($fresh),
                $actor,
                ['title' => "Plot KYC Receipt — {$locked->booking_number}"],
            );

            DocumentTimeline::record(
                DocumentActivityType::PlotKycReceiptGenerated,
                "Plot KYC receipt generated for {$locked->booking_number}.",
                $locked, null, ['document_id' => $document->id], $actor,
            );

            Log::info('plot_kyc_receipt.generated', [
                'booking_id' => $locked->id,
                'document_id' => $document->id,
                'by' => $actor->id,
            ]);

            return $document->load('versions');
        });
    }

    /**
     * @param  list<array{name?: string|null, address?: string|null, mobile?: string|null}>  $witnesses
     */
    private function syncWitnesses(Booking $booking, array $witnesses): void
    {
        foreach ([1, 2] as $number) {
            $w = $witnesses[$number - 1] ?? null;
            $name = trim((string) ($w['name'] ?? ''));

            if ($name === '') {
                BookingWitness::query()
                    ->where('booking_id', $booking->id)
                    ->where('witness_number', $number)
                    ->delete();

                continue;
            }

            BookingWitness::updateOrCreate(
                ['booking_id' => $booking->id, 'witness_number' => $number],
                [
                    'name' => $name,
                    'address' => ($w['address'] ?? null) ?: null,
                    'mobile' => ($w['mobile'] ?? null) ?: null,
                ],
            );
        }
    }
}
