<?php

declare(strict_types=1);

namespace App\Services\Reports\Export;

use App\Enums\ExportFormat;
use App\Support\Reports\ReportExportPayload;
use App\Support\Reports\XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * XLSX export (M11.5) — one sheet of title / filters / timestamp, then one
 * sheet per report table with a bold header row, the aggregated rows and a
 * bold totals row where the table provides one.
 *
 * Numbers are written as numbers (not formatted strings) so the recipient can
 * sort and sum in Excel; the display formatting lives on the screen and in the
 * PDF / CSV. Uses the dependency-free {@see XlsxWriter}.
 */
class XlsxReportExporter implements ReportExporter
{
    public function format(): ExportFormat
    {
        return ExportFormat::Xlsx;
    }

    public function export(ReportExportPayload $payload): BinaryFileResponse
    {
        $writer = new XlsxWriter;

        // Sheet 1 — the cover: title, period, generation info, applied filters.
        $cover = [
            [$payload->title],
            ['Period', $payload->periodLabel],
            ['Generated', $payload->generatedAtLabel().' by '.$payload->generatedBy],
            [],
            ['Applied filters'],
        ];
        foreach ($payload->filterSummary as $label => $value) {
            $cover[] = [$label, $value];
        }
        $writer->addSheet('Summary', $cover, boldRows: [0, 4]);

        foreach ($payload->tables as $table) {
            $rows = [[$table->title]];
            $boldRows = [0];

            if ($table->note !== null) {
                $rows[] = [$table->note];
            }

            if ($table->isEmpty()) {
                $rows[] = ['No data for the selected filters.'];
                $writer->addSheet($table->title, $rows, $boldRows);

                continue;
            }

            $headingRow = count($rows);
            $rows[] = $table->headings();
            $boldRows[] = $headingRow;

            foreach ($table->rows as $row) {
                $rows[] = $table->rawCells($row);
            }

            if ($table->totals !== null) {
                $boldRows[] = count($rows);
                $rows[] = $table->rawCells($table->totals);
            }

            $writer->addSheet($table->title, $rows, $boldRows);
        }

        $path = tempnam(sys_get_temp_dir(), 'rptxlsx');
        $writer->save($path);

        return response()
            ->download($path, $payload->filename().'.xlsx', [
                'Content-Type' => ExportFormat::Xlsx->contentType(),
            ])
            ->deleteFileAfterSend(true);
    }
}
