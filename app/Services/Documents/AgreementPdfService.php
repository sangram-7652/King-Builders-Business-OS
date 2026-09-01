<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\Agreement;
use App\Support\Branding;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;

/**
 * Renders an agreement to a PDF using the shared M7 dompdf + {@see Branding}
 * document architecture (M9). Returns an {@see UploadedFile} so the result can
 * flow through the normal document-versioning pipeline.
 */
class AgreementPdfService
{
    public function __construct(private readonly Branding $branding) {}

    public function render(Agreement $agreement): string
    {
        $agreement->loadMissing([
            'booking.project', 'booking.block', 'booking.plot',
            'booking.bookingBuyers.buyer', 'preparedBy',
        ]);

        $buyers = $agreement->booking->bookingBuyers->map(fn ($bb) => [
            'name' => $bb->buyer?->fullName() ?? '—',
            'customer_code' => $bb->buyer?->customer_code ?? '—',
            'ownership' => rtrim(rtrim(number_format((float) $bb->ownership_percentage, 2), '0'), '.'),
            'is_primary' => (bool) $bb->is_primary,
        ])->all();

        return Pdf::loadView('agreements.pdf', [
            'agreement' => $agreement,
            'buyers' => $buyers,
            'brand' => ['name' => $this->branding->name, 'primary' => $this->branding->colors['primary']],
        ])->setPaper('a4')->output();
    }

    /** Render + wrap as a temp UploadedFile for the document pipeline. */
    public function renderAsUpload(Agreement $agreement): UploadedFile
    {
        $bytes = $this->render($agreement);
        $tmp = tempnam(sys_get_temp_dir(), 'agr').'.pdf';
        file_put_contents($tmp, $bytes);

        return new UploadedFile(
            $tmp,
            "{$agreement->agreement_number}.pdf",
            'application/pdf',
            null,
            true, // test mode — bypass is_uploaded_file()
        );
    }
}
