<?php

declare(strict_types=1);

namespace App\Services\Reports\Export;

use App\Enums\ExportFormat;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves the {@see ReportExporter} for a given {@see ExportFormat} (M11.5).
 */
class ReportExportManager
{
    public function __construct(private readonly Container $container) {}

    public function for(ExportFormat $format): ReportExporter
    {
        return $this->container->make(match ($format) {
            ExportFormat::Csv => CsvReportExporter::class,
            ExportFormat::Xlsx => XlsxReportExporter::class,
            ExportFormat::Pdf => PdfReportExporter::class,
            ExportFormat::Print => PrintReportExporter::class,
        });
    }
}
