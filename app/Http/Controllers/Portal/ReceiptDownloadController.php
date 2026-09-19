<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Enums\CustomerActivityType;
use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\Receipt;
use App\Support\Branding;
use App\Support\Payments\ReceiptPdfData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Customer-scoped receipt PDF (M15.2). Reuses the M7 `receipts.pdf` Blade +
 * dompdf — no separate PDF system. Ownership is enforced here (never the URL
 * id): the receipt must belong to the signed-in customer, by buyer id or by a
 * booking they co-own, and must not be voided.
 */
class ReceiptDownloadController extends Controller
{
    public function __invoke(Receipt $receipt, Branding $branding): Response
    {
        /** @var Buyer $customer */
        $customer = Auth::guard('customer')->user();

        $bookingIds = $customer->bookingBuyers()->pluck('booking_id');

        abort_unless(
            $receipt->voided_at === null
                && ($receipt->buyer_id === $customer->getKey() || $bookingIds->contains($receipt->booking_id)),
            404,
        );

        $customer->recordPortalActivity(CustomerActivityType::ReceiptDownloaded, "Downloaded receipt {$receipt->receipt_number}.", [
            'receipt_id' => $receipt->id,
        ]);

        $pdf = Pdf::loadView('receipts.pdf', [
            'receipt' => $receipt,
            'brand' => $branding,
            'extra' => ReceiptPdfData::build($receipt),
        ])->setPaper('a4');

        return $pdf->stream("{$receipt->receipt_number}.pdf");
    }
}
