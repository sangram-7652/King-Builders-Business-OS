<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Actions\Documents\UploadDocumentAction;
use App\Actions\Possession\GeneratePossessionCertificateAction;
use App\Enums\DocumentActivityType;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\BookingWitness;
use App\Models\Document;
use App\Models\Masters\DocumentType;
use App\Models\Plot;
use App\Models\User;
use App\Services\Documents\PlotKycReceiptPdfService;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Documents\DocumentTimeline;
use Illuminate\Support\Facades\Log;

/**
 * Generates the Plot KYC / Registry KYC Receipt for a confirmed booking.
 * Reuses the exact M9 architecture already proven by
 * {@see GeneratePossessionCertificateAction}: the receipt is a versioned
 * `PLOT_KYC_RECEIPT` document on the booking, stored through
 * {@see UploadDocumentAction} — no second document or payment system.
 *
 * Optionally accepts the transaction-specific details this receipt needs that
 * nothing else in the system captures (Vikray Muly declared value, up to two
 * witnesses, and the plot's own land-record fields when the Plot doesn't
 * already have them) and persists them before rendering. All of it is
 * optional — omitting or blanking any of it never blocks generation.
 *
 * The six land-record fields (`village_name`, `gata_number`, four
 * `boundary_*`) live on `plots` — the SAME columns the Plot create/edit form
 * writes to (`App\Actions\Plots\UpdatePlot`). This action does not add a
 * second land-record store: it fills gaps on the existing Plot record so a
 * later receipt (or the Plot screen itself) sees the same value. A field the
 * Plot already had is left alone unless the caller explicitly supplies a
 * different value for it (the form always submits the Plot's current value
 * back, so "unless explicitly edited" falls out naturally).
 *
 * Calling this again on an already-generated receipt does not create a new
 * `Document` row — it appends a new `DocumentVersion` to the existing one
 * (old versions are kept; see {@see UploadDocumentAction}).
 */
class GeneratePlotKycReceiptAction
{
    use RunsInTransaction;

    public function __construct(
        private readonly PlotKycReceiptPdfService $pdf,
        private readonly UploadDocumentAction $upload,
    ) {}

    /**
     * @param  array{vikray_muly_amount?: string|null, witnesses?: list<array{name?: string|null, address?: string|null, mobile?: string|null}>, plot?: array{village_name?: string|null, gata_number?: string|null, boundary_east?: string|null, boundary_west?: string|null, boundary_north?: string|null, boundary_south?: string|null}}  $details  already-validated
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

            if (array_key_exists('plot', $details)) {
                $this->syncPlotLandRecords($locked->plot, $details['plot']);
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

    /**
     * Fills the plot's own land-record columns — the SAME `plots.village_name`
     * / `gata_number` / `boundary_*` columns the Plot form writes to, never a
     * second store. The Generate/Regenerate form always submits the plot's
     * current value for a field it didn't touch, so a plain overwrite here
     * already satisfies "preserve unless explicitly edited" — no
     * touched/untouched tracking is needed. `Eloquent::save()` issues no
     * UPDATE at all when nothing is actually dirty.
     *
     * @param  array{village_name?: string|null, gata_number?: string|null, boundary_east?: string|null, boundary_west?: string|null, boundary_north?: string|null, boundary_south?: string|null}  $fields
     */
    private function syncPlotLandRecords(Plot $plot, array $fields): void
    {
        $plot->forceFill([
            'village_name' => ($fields['village_name'] ?? null) ?: null,
            'gata_number' => ($fields['gata_number'] ?? null) ?: null,
            'boundary_east' => ($fields['boundary_east'] ?? null) ?: null,
            'boundary_west' => ($fields['boundary_west'] ?? null) ?: null,
            'boundary_north' => ($fields['boundary_north'] ?? null) ?: null,
            'boundary_south' => ($fields['boundary_south'] ?? null) ?: null,
        ])->save();
    }
}
