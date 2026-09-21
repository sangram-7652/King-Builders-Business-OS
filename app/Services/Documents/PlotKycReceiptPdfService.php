<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Actions\Documents\UploadDocumentAction;
use App\Models\Booking;
use App\Services\Possession\PossessionCertificateService;
use App\Support\Branding;
use App\Support\Documents\PlotKycReceiptPdfData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;

/**
 * Renders the Plot KYC / Registry KYC Receipt to a PDF using the shared M7/M9
 * dompdf + {@see Branding} document architecture — the same library and
 * pattern as {@see AgreementPdfService} and
 * {@see PossessionCertificateService}. Returns an
 * {@see UploadedFile} so the result flows through the normal M9
 * document-versioning pipeline ({@see UploadDocumentAction}).
 */
class PlotKycReceiptPdfService
{
    public function __construct(
        private readonly PlotKycReceiptPdfData $data,
        private readonly Branding $branding,
    ) {}

    public function render(Booking $booking): string
    {
        return Pdf::loadView('plot-kyc-receipt.pdf', [
            'booking' => $booking,
            'brand' => $this->branding,
            'data' => $this->data->build($booking),
        ])->setPaper('a4')->output();
    }

    /** Render + wrap as a temp UploadedFile for the document pipeline. */
    public function renderAsUpload(Booking $booking): UploadedFile
    {
        $bytes = $this->render($booking);
        $tmp = tempnam(sys_get_temp_dir(), 'pkr').'.pdf';
        file_put_contents($tmp, $bytes);

        return new UploadedFile(
            $tmp,
            "{$booking->booking_number}-plot-kyc-receipt.pdf",
            'application/pdf',
            null,
            true, // test mode — bypass is_uploaded_file()
        );
    }
}
