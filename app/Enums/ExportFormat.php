<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Reports\ReportExportPayload;

/**
 * The formats a report can be exported to (M11.5).
 *
 * `print` is not a download — it renders a stripped, print-optimised HTML view
 * the browser prints. The other three are file downloads produced from the
 * exact same {@see ReportExportPayload} the screen builds.
 */
enum ExportFormat: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';
    case Pdf = 'pdf';
    case Print = 'print';

    public function label(): string
    {
        return match ($this) {
            self::Csv => 'CSV',
            self::Xlsx => 'Excel',
            self::Pdf => 'PDF',
            self::Print => 'Print',
        };
    }

    public function extension(): string
    {
        return match ($this) {
            self::Csv => 'csv',
            self::Xlsx => 'xlsx',
            self::Pdf => 'pdf',
            self::Print => 'html',
        };
    }

    public function contentType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv; charset=UTF-8',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Pdf => 'application/pdf',
            self::Print => 'text/html; charset=UTF-8',
        };
    }

    /** A real file download (vs. an in-browser view). */
    public function isDownload(): bool
    {
        return $this !== self::Print;
    }

    /**
     * @return array<string, string> value => label, for the export menu
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $f) => [$f->value => $f->label()])
            ->all();
    }
}
