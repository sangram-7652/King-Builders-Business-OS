<?php

declare(strict_types=1);

namespace App\Services\Reports\Export;

use App\Enums\ExportFormat;
use App\Support\Reports\ReportExportPayload;
use App\Support\Reports\ReportTable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streamed, UTF-8 CSV export (M11.5).
 *
 * Rows are written one at a time straight to the output buffer via `fputcsv`
 * (RFC-4180 escaping, `\r\n` line endings) so a wide window never materialises
 * in memory. A BOM is prepended so Excel opens the UTF-8 file correctly.
 * Multiple report tables are concatenated with a blank-line separator and a
 * title row each.
 */
class CsvReportExporter implements ReportExporter
{
    public function format(): ExportFormat
    {
        return ExportFormat::Csv;
    }

    public function export(ReportExportPayload $payload): StreamedResponse
    {
        $filename = $payload->filename().'.csv';

        return new StreamedResponse(function () use ($payload): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

            $this->line($out, [$payload->title]);
            $this->line($out, ['Period', $payload->periodLabel]);
            $this->line($out, ['Generated', $payload->generatedAtLabel().' by '.$payload->generatedBy]);
            foreach ($payload->filterSummary as $label => $value) {
                $this->line($out, [$label, $value]);
            }
            $this->blank($out);

            foreach ($payload->tables as $table) {
                $this->writeTable($out, $table);
                $this->blank($out);
            }

            fclose($out);
        }, 200, [
            'Content-Type' => ExportFormat::Csv->contentType(),
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @param  resource  $out
     */
    private function writeTable($out, ReportTable $table): void
    {
        $this->line($out, [$table->title]);

        if ($table->note !== null) {
            $this->line($out, [$table->note]);
        }

        if ($table->isEmpty()) {
            $this->line($out, ['No data for the selected filters.']);

            return;
        }

        $this->line($out, $table->headings());

        foreach ($table->rows as $row) {
            $this->line($out, $table->displayCells($row));
        }

        if ($table->totals !== null) {
            $this->line($out, $table->displayCells($table->totals));
        }
    }

    /**
     * @param  resource  $out
     * @param  list<scalar|null>  $cells
     */
    private function line($out, array $cells): void
    {
        // escape: '' → strict RFC-4180 quoting (and no PHP 8.4 deprecation).
        fputcsv($out, array_map(static fn ($v) => $v ?? '', $cells), ',', '"', '', "\r\n");
    }

    /**
     * @param  resource  $out
     */
    private function blank($out): void
    {
        fwrite($out, "\r\n");
    }
}
