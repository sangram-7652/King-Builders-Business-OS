<?php

declare(strict_types=1);

namespace App\Support\Documents;

use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\BookingWitness;
use App\Services\Documents\PlotKycReceiptPdfService;
use App\Services\Payments\PaymentLedger;
use App\Support\Branding;
use Illuminate\Support\Collection;

/**
 * Builds the immutable data set the Plot KYC / Registry KYC Receipt PDF is
 * rendered from — the ONLY place that reads the booking/plot/buyer/payment
 * graph for this document. The Blade template never queries the database
 * directly (see {@see PlotKycReceiptPdfService}).
 *
 * Field ownership (see the P0 audit this class implements):
 *  - Village / Gata No. / plot Chauhaddi (boundaries) — PLOT-level, permanent
 *    land records (`plots.village_name` etc.), independent of any booking.
 *  - Vikray Muly (declared registry sale value) / Witness 1 / Witness 2 —
 *    BOOKING-level, transaction-specific (`bookings.vikray_muly_amount`,
 *    `booking_witnesses`).
 *  - Seller / Company / Director — tenant-wide, sourced from {@see Branding}
 *    (config/branding.php), never stored per-booking.
 *  - Registry Buyer (name/mobile/address/PAN shown on THIS document) —
 *    BOOKING-level, receipt-specific (`bookings.registry_buyer_*`). Applied
 *    ONLY to the primary buyer's displayed identity, per field, when that
 *    field is set; every other co-owner (and any unset field) still shows
 *    the buyer's own real data. The booking's actual buyer/ownership record
 *    (`booking_buyers`, `Buyer`) is never modified by this override — see
 *    `GeneratePlotKycReceiptAction::syncRegistryBuyer()`.
 *  - Financial figures — reuse {@see PaymentLedger}
 *    exclusively; no payment math is duplicated here. "Paid PLC" / "Balance
 *    PLC" are deliberately left untracked (null) — payments are not itemised
 *    against price-line components, and this document must never present an
 *    estimate as an actual traced figure (see the class-level note on
 *    `plcPaid`/`plcBalance` below).
 */
final class PlotKycReceiptPdfData
{
    public function __construct(
        private readonly PaymentLedger $ledger,
        private readonly Branding $branding,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Booking $booking): array
    {
        $booking->loadMissing([
            'project', 'block', 'plot.dimension',
            'bookingBuyers.buyer.city',
            'witnesses',
            'payments' => fn ($q) => $q->where('status', PaymentStatus::Success->value)->orderBy('payment_date'),
        ]);

        $plot = $booking->plot;
        $dimension = $plot?->dimension;

        return [
            'documentTitle' => 'REGISTRY KYC',
            'documentSubtitle' => 'PART-1',

            'village' => [
                'villageName' => $plot?->village_name,
                'gataNumber' => $plot?->gata_number,
                'vikrayMulyAmount' => $booking->vikray_muly_amount,
            ],

            'plot' => [
                'plotNumber' => $plot?->plot_number,
                'area' => $plot?->areaLabel(),
                // width/length are the shared plot-dimension master (see
                // PlotDimension) — mapped to Front/Depth for this document
                // only; no separate Front/Depth columns are stored.
                'front' => $dimension?->width !== null ? self::trimDecimal($dimension->width) : null,
                'depth' => $dimension?->length !== null ? self::trimDecimal($dimension->length) : null,
                'siteName' => $booking->project?->name,
            ],

            'chauhaddi' => [
                'east' => $plot?->boundary_east,
                'west' => $plot?->boundary_west,
                'north' => $plot?->boundary_north,
                'south' => $plot?->boundary_south,
            ],

            'seller' => [
                'companyName' => $this->branding->name,
                'directorName' => $this->branding->contact['director_name'] ?? null,
                'address' => $this->branding->contact['head_office_address'] ?? null,
                'pan' => $this->branding->contact['pan_number'] ?? null,
                'mobile' => $this->branding->contact['phone'] ?? null,
            ],

            'buyers' => $booking->bookingBuyers->map(function ($bb) use ($booking) {
                $isPrimary = (bool) $bb->is_primary;

                return [
                    'name' => self::registryOverride($isPrimary, $booking->registry_buyer_name) ?? $bb->buyer?->fullName() ?? '—',
                    'address' => self::registryOverride($isPrimary, $booking->registry_buyer_address) ?? $bb->buyer?->address,
                    'pan' => self::registryOverride($isPrimary, $booking->registry_buyer_pan) ?? $bb->buyer?->pan_number,
                    'mobile' => self::registryOverride($isPrimary, $booking->registry_buyer_mobile) ?? $bb->buyer?->phone,
                    'isPrimary' => $isPrimary,
                ];
            })->all(),

            'witnesses' => self::witnessSlots($booking->witnesses),

            'payments' => $booking->payments->map(fn ($p) => [
                'referenceNumber' => $p->reference_number,
                'amount' => $p->amount,
                'bankName' => $p->cheque_bank_name,
                'date' => $p->payment_date,
            ])->all(),

            'financial' => [
                'totalPlotAmount' => $booking->final_amount,
                'paidPlotAmount' => $this->ledger->bookingPaid($booking)->store(),
                'balanceAmount' => $this->ledger->bookingOutstanding($booking)->store(),
                'totalPlcAmount' => $booking->plc_amount,
                // Deliberately untracked — see class docblock. Never derive an
                // estimate here; the Blade template prints "Not separately
                // tracked" whenever these are null.
                'paidPlcAmount' => null,
                'balancePlcAmount' => null,
            ],
        ];
    }

    /**
     * Always returns exactly two slots (Witness 1, Witness 2), blank when not
     * recorded — witnesses are optional and must never block generation.
     *
     * @param  Collection<int, BookingWitness>  $witnesses
     * @return list<array{name: ?string, address: ?string, mobile: ?string}>
     */
    private static function witnessSlots(Collection $witnesses): array
    {
        $byNumber = $witnesses->keyBy('witness_number');

        return collect([1, 2])->map(fn (int $n) => [
            'name' => $byNumber->get($n)?->name,
            'address' => $byNumber->get($n)?->address,
            'mobile' => $byNumber->get($n)?->mobile,
        ])->all();
    }

    /** A registry-buyer override only ever applies to the primary buyer, and only when actually set. */
    private static function registryOverride(bool $isPrimary, ?string $value): ?string
    {
        return $isPrimary && $value !== null && $value !== '' ? $value : null;
    }

    private static function trimDecimal(string $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }
}
