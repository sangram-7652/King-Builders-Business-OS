<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Receipt;
use App\Support\Branding;
use App\Support\Payments\ReceiptPdfData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Renders a receipt as a tenant-branded PDF using the project's document
 * architecture (Blade + {@see Branding}) — no separate PDF system.
 */
class ReceiptPdfController extends Controller
{
    public function __invoke(Receipt $receipt, Branding $branding): Response
    {
        Gate::authorize('generate', $receipt);

        $pdf = Pdf::loadView('receipts.pdf', [
            'receipt' => $receipt,
            'brand' => $branding,
            'extra' => ReceiptPdfData::build($receipt),
        ])->setPaper('a4');

        return $pdf->stream("{$receipt->receipt_number}.pdf");
    }
}
