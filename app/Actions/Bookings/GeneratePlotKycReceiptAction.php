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
use App\Support\Branding;
use App\Support\BrandingConfigWriter;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Documents\DocumentTimeline;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates the Plot KYC / Registry KYC Receipt for a confirmed booking.
 * Reuses the exact M9 architecture already proven by
 * {@see GeneratePossessionCertificateAction}: the receipt is a versioned
 * `PLOT_KYC_RECEIPT` document on the booking, stored through
 * {@see UploadDocumentAction} — no second document or payment system.
 *
 * Optionally accepts the transaction-specific details this receipt needs that
 * nothing else in the system captures (Vikray Muly declared value, up to two
 * witnesses, the plot's own land-record fields, and the seller's Director
 * Name / PAN) and persists them before rendering. All of it is optional —
 * omitting or blanking any of it never blocks generation.
 *
 * The six land-record fields (`village_name`, `gata_number`, four
 * `boundary_*`) live on `plots` — the SAME columns the Plot create/edit form
 * writes to (`App\Actions\Plots\UpdatePlot`). This action does not add a
 * second land-record store: it fills gaps on the existing Plot record so a
 * later receipt (or the Plot screen itself) sees the same value. A field the
 * Plot already had is left alone unless the caller explicitly supplies a
 * different value for it (the form always submits the Plot's current value
 * back, so "unless explicitly edited" falls out naturally). Seller Director
 * Name / PAN work the same way but through {@see Branding} / `.env`
 * ({@see BrandingConfigWriter}) instead of a database column — there is no
 * database-backed settings store for branding config.
 *
 * Calling this again on an already-generated receipt does not create a new
 * `Document` row — it appends a new `DocumentVersion` to the existing one
 * (old versions are kept; see {@see UploadDocumentAction}).
 */
class GeneratePlotKycReceiptAction
{
    use RunsInTransaction;

    public function __construct(
        private readonly UploadDocumentAction $upload,
        private readonly BrandingConfigWriter $brandingWriter,
    ) {}

    /**
     * @param  array{vikray_muly_amount?: string|null, witnesses?: list<array{name?: string|null, address?: string|null, mobile?: string|null}>, plot?: array{village_name?: string|null, gata_number?: string|null, boundary_east?: string|null, boundary_west?: string|null, boundary_north?: string|null, boundary_south?: string|null}, seller?: array{company_name?: string|null, director_name?: string|null, address?: string|null, pan_number?: string|null, mobile?: string|null}, registry_buyer?: array{name?: string|null, mobile?: string|null, address?: string|null, pan?: string|null}}  $details  already-validated
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

            if (array_key_exists('seller', $details)) {
                $this->syncSellerConfig($details['seller']);
            }

            if (array_key_exists('registry_buyer', $details)) {
                $this->syncRegistryBuyer($locked, $details['registry_buyer']);
            }

            $type = DocumentType::query()->where('code', 'PLOT_KYC_RECEIPT')->firstOrFail();

            $fresh = $locked->fresh([
                'project', 'block', 'plot.dimension',
                'bookingBuyers.buyer.city', 'witnesses', 'payments',
            ]);

            // Resolved fresh, never constructor-injected: Branding is a
            // request-wide singleton already resolved — with whatever config
            // held BEFORE syncSellerConfig() ran above — the moment this
            // request booted (AppServiceProvider::boot() shares it to every
            // view). Forgetting + re-resolving it here is the only way this
            // SAME render can reflect a seller value just persisted above.
            app()->forgetInstance(Branding::class);
            $pdf = app(PlotKycReceiptPdfService::class);

            $document = $this->upload->handle(
                $locked,
                $type,
                $pdf->renderAsUpload($fresh),
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

    /**
     * Fills all five Seller / Company fields — the SAME `branding.*` config
     * keys (backed by `.env`, via {@see BrandingConfigWriter}) every other
     * Plot KYC Receipt seller field already reads, never a second config
     * system or table. Company Name / Address / Mobile are the SAME
     * tenant-wide `branding.name` / `contact.head_office_address` /
     * `contact.phone` values every other document (payment receipts,
     * possession certificates, the portal, the app shell) already reads —
     * editing them here changes them everywhere, by design, since `.env` is
     * the one existing branding source; there is no per-document override.
     *
     * The in-memory `config()` value is updated immediately — regardless of
     * whether the `.env` write below succeeds — so the operator's entered
     * value is never silently missing from the receipt being generated right
     * now. The `.env` write itself is best-effort and failing it (e.g. a
     * read-only filesystem in some deployments) only means the NEXT
     * Generate/Regenerate won't see it prefilled; it must never block this
     * one.
     *
     * @param  array{company_name?: string|null, director_name?: string|null, address?: string|null, pan_number?: string|null, mobile?: string|null}  $seller
     */
    private function syncSellerConfig(array $seller): void
    {
        // field => [config path, .env key]
        $map = [
            'company_name' => ['branding.name', 'BRAND_NAME'],
            'director_name' => ['branding.contact.director_name', 'BRAND_DIRECTOR_NAME'],
            'address' => ['branding.contact.head_office_address', 'BRAND_HEAD_OFFICE_ADDRESS'],
            'pan_number' => ['branding.contact.pan_number', 'BRAND_PAN_NUMBER'],
            'mobile' => ['branding.contact.phone', 'BRAND_PHONE'],
        ];

        $envUpdates = [];
        $configUpdates = [];

        foreach ($map as $field => [$configPath, $envKey]) {
            if (! array_key_exists($field, $seller)) {
                continue;
            }

            $new = trim((string) ($seller[$field] ?? ''));
            $current = trim((string) (config($configPath) ?? ''));

            if ($new !== $current) {
                $envUpdates[$envKey] = $new;
                $configUpdates[$configPath] = $new !== '' ? $new : null;
            }
        }

        if ($envUpdates === []) {
            return;
        }

        foreach ($configUpdates as $path => $value) {
            config([$path => $value]);
        }

        try {
            $this->brandingWriter->update($envUpdates);
        } catch (Throwable $e) {
            Log::warning('plot_kyc_receipt.seller_config_persist_failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Fills the Registry Buyer — the name/mobile/address/PAN shown on THIS
     * document, which may deliberately differ from the booking's actual
     * buyer (see the `registry_buyer_*` columns on `bookings`, added
     * specifically for this). Plain overwrite, same as
     * {@see self::syncPlotLandRecords()}: the Generate/Regenerate form always
     * submits the current value for a field it didn't touch, and
     * `Eloquent::save()` issues no UPDATE when nothing is actually dirty.
     *
     * This NEVER touches `booking_buyers`, `bookings.buyer` data, or the
     * `Buyer` master — the booking's real buyer/ownership record is
     * completely unaffected by what is entered here.
     *
     * @param  array{name?: string|null, mobile?: string|null, address?: string|null, pan?: string|null}  $fields
     */
    private function syncRegistryBuyer(Booking $booking, array $fields): void
    {
        $booking->forceFill([
            'registry_buyer_name' => ($fields['name'] ?? null) ?: null,
            'registry_buyer_mobile' => ($fields['mobile'] ?? null) ?: null,
            'registry_buyer_address' => ($fields['address'] ?? null) ?: null,
            'registry_buyer_pan' => ($fields['pan'] ?? null) ?: null,
        ])->save();
    }
}
