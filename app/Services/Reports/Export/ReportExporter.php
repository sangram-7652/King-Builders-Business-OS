<?php

declare(strict_types=1);

namespace App\Services\Reports\Export;

use App\Enums\ExportFormat;
use App\Support\Reports\ReportExportPayload;
use Symfony\Component\HttpFoundation\Response;

/**
 * One output format for a report export (M11.5).
 *
 * Implementations receive a fully-built {@see ReportExportPayload} (identical to
 * what the screen renders) and are responsible only for serialisation — no
 * querying, no business logic.
 */
interface ReportExporter
{
    public function format(): ExportFormat;

    public function export(ReportExportPayload $payload): Response;
}
