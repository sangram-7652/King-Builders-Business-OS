<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Services\Reports\Export\XlsxReportExporter;
use RuntimeException;
use ZipArchive;

/**
 * A dependency-free minimal XLSX writer (M11.5).
 *
 * The project already ships no spreadsheet package and the export needs are
 * modest (a handful of small aggregate sheets), so rather than pull in
 * phpspreadsheet we emit the Office Open XML parts by hand into a ZIP via the
 * built-in {@see ZipArchive}. Strings use inline storage (`t="inlineStr"`) so
 * there is no shared-string table to maintain; numbers are written untyped so
 * Excel keeps them sortable. One bold style is defined for header / total rows.
 *
 * This is intentionally not a general-purpose library — it supports exactly
 * what {@see XlsxReportExporter} needs.
 */
final class XlsxWriter
{
    /** @var list<array{name: string, rows: list<list<scalar|null>>, bold: list<int>}> */
    private array $sheets = [];

    /**
     * @param  list<list<scalar|null>>  $rows  each cell: int|float → number, anything else → string
     * @param  list<int>  $boldRows  0-indexed rows to render bold (headers, totals)
     */
    public function addSheet(string $name, array $rows, array $boldRows = []): self
    {
        $this->sheets[] = [
            'name' => $this->safeSheetName($name, count($this->sheets) + 1),
            'rows' => $rows,
            'bold' => $boldRows,
        ];

        return $this;
    }

    /** Write the workbook to `$path` (an .xlsx file). */
    public function save(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot open {$path} for writing.");
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());

        foreach ($this->sheets as $i => $sheet) {
            $zip->addFromString('xl/worksheets/sheet'.($i + 1).'.xml', $this->sheetXml($sheet['rows'], $sheet['bold']));
        }

        $zip->close();
    }

    private function contentTypes(): string
    {
        $overrides = '';
        foreach ($this->sheets as $i => $_) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.($i + 1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$overrides
            .'</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbook(): string
    {
        $sheets = '';
        foreach ($this->sheets as $i => $sheet) {
            $sheets .= '<sheet name="'.$this->esc($sheet['name']).'" sheetId="'.($i + 1).'" r:id="rId'.($i + 1).'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheets.'</sheets></workbook>';
    }

    private function workbookRels(): string
    {
        $rels = '';
        foreach ($this->sheets as $i => $_) {
            $rels .= '<Relationship Id="rId'.($i + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.($i + 1).'.xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            .'<borders count="1"><border/></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    /**
     * @param  list<list<scalar|null>>  $rows
     * @param  list<int>  $boldRows
     */
    private function sheetXml(array $rows, array $boldRows): string
    {
        $bold = array_fill_keys($boldRows, true);
        $body = '';

        foreach ($rows as $r => $cells) {
            $rowNum = $r + 1;
            $isBold = isset($bold[$r]);
            $cellsXml = '';

            foreach (array_values($cells) as $c => $value) {
                $ref = $this->columnLetter($c).$rowNum;
                $style = $isBold ? ' s="1"' : '';

                if (is_int($value) || is_float($value)) {
                    $cellsXml .= '<c r="'.$ref.'"'.$style.'><v>'.$value.'</v></c>';
                } elseif ($value === null || $value === '') {
                    $cellsXml .= '<c r="'.$ref.'"'.$style.'/>';
                } else {
                    $cellsXml .= '<c r="'.$ref.'"'.$style.' t="inlineStr"><is><t xml:space="preserve">'.$this->esc((string) $value).'</t></is></c>';
                }
            }

            $body .= '<row r="'.$rowNum.'">'.$cellsXml.'</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.$body.'</sheetData></worksheet>';
    }

    private function columnLetter(int $index): string
    {
        $letter = '';
        $index++;
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letter = chr(65 + $mod).$letter;
            $index = intdiv($index - 1, 26);
        }

        return $letter;
    }

    private function safeSheetName(string $name, int $ordinal): string
    {
        $clean = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', ' ', $name) ?? $name;
        $clean = trim(mb_substr($clean, 0, 31));

        return $clean === '' ? 'Sheet'.$ordinal : $clean;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
