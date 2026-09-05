<?php

declare(strict_types=1);

namespace App\Services\Reports\Export;

use App\Enums\ExportFormat;
use App\Support\Reports\ReportExportPayload;
use Illuminate\Http\Response;

/**
 * Print export (M11.5) — a stripped, print-optimised HTML view (no sidebar, no
 * navigation, no export controls) that triggers the browser print dialog on
 * load. Same {@see ReportExportPayload} as every other format.
 */
class PrintReportExporter implements ReportExporter
{
    public function format(): ExportFormat
    {
        return ExportFormat::Print;
    }

    public function export(ReportExportPayload $payload): Response
    {
        return new Response(
            view('reports.exports.print', ['payload' => $payload])->render(),
            200,
            ['Content-Type' => ExportFormat::Print->contentType()],
        );
    }
}
