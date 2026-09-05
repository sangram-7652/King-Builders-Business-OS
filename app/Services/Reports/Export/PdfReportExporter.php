<?php

declare(strict_types=1);

namespace App\Services\Reports\Export;

use App\Enums\ExportFormat;
use App\Support\Branding;
use App\Support\Reports\ReportExportPayload;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF export (M11.5) — reuses the project's existing dompdf architecture
 * (Blade view + {@see Branding}), the same one receipts, agreements and
 * possession certificates use. Landscape A4, multi-page, with a repeating
 * header, the applied-filter summary and per-table totals.
 */
class PdfReportExporter implements ReportExporter
{
    public function __construct(private readonly Branding $branding) {}

    public function format(): ExportFormat
    {
        return ExportFormat::Pdf;
    }

    public function export(ReportExportPayload $payload): Response
    {
        $pdf = Pdf::loadView('reports.exports.pdf', [
            'payload' => $payload,
            'brand' => [
                'name' => $this->branding->name,
                'primary' => $this->branding->colors['primary'],
            ],
        ])->setPaper('a4', 'landscape');

        return $pdf->download($payload->filename().'.pdf');
    }
}
