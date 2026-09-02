<?php

declare(strict_types=1);

namespace App\Services\Possession;

use App\Models\PossessionCase;
use App\Support\Branding;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;

/**
 * Renders the possession certificate to a PDF using the shared M7/M9 dompdf +
 * {@see Branding} architecture (M10). Returns an {@see UploadedFile} so the
 * result flows through the normal M9 document-versioning pipeline.
 */
class PossessionCertificateService
{
    public function __construct(private readonly Branding $branding) {}

    public function render(PossessionCase $case): string
    {
        $case->loadMissing([
            'booking.project', 'booking.block', 'booking.plot',
            'booking.bookingBuyers.buyer', 'handover', 'assignedTo',
        ]);

        $owners = $case->booking->bookingBuyers->map(fn ($bb) => [
            'name' => $bb->buyer?->fullName() ?? '—',
            'customer_code' => $bb->buyer?->customer_code ?? '—',
            'is_primary' => (bool) $bb->is_primary,
        ])->all();

        return Pdf::loadView('possession.certificate', [
            'case' => $case,
            'booking' => $case->booking,
            'owners' => $owners,
            'handoverDate' => $case->handover?->handover_date ?? $case->completed_at,
            'representative' => $case->assignedTo?->name ?? 'Authorised Representative',
            'brand' => ['name' => $this->branding->name, 'primary' => $this->branding->colors['primary']],
        ])->setPaper('a4')->output();
    }

    public function renderAsUpload(PossessionCase $case): UploadedFile
    {
        $bytes = $this->render($case);
        $tmp = tempnam(sys_get_temp_dir(), 'pos').'.pdf';
        file_put_contents($tmp, $bytes);

        return new UploadedFile(
            $tmp,
            "{$case->case_number}-certificate.pdf",
            'application/pdf',
            null,
            true, // test mode — bypass is_uploaded_file()
        );
    }
}
