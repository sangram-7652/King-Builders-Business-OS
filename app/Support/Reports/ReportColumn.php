<?php

declare(strict_types=1);

namespace App\Support\Reports;

/**
 * One column definition for a {@see ReportTable} (M11.5).
 *
 * `format` drives both the on-screen cell rendering and every exporter, so a
 * currency figure looks the same in the browser, the CSV, the XLSX and the PDF.
 * Exporters emit the raw numeric value for `number|currency|percent` (so the
 * spreadsheet stays sortable) and a formatted string only for display targets.
 */
final readonly class ReportColumn
{
    /**
     * @param  'text'|'number'|'currency'|'percent'|'date'  $format
     * @param  'left'|'right'|'center'  $align
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $format = 'text',
        public string $align = 'left',
    ) {}

    public static function text(string $key, string $label): self
    {
        return new self($key, $label, 'text', 'left');
    }

    public static function number(string $key, string $label): self
    {
        return new self($key, $label, 'number', 'right');
    }

    public static function currency(string $key, string $label): self
    {
        return new self($key, $label, 'currency', 'right');
    }

    public static function percent(string $key, string $label): self
    {
        return new self($key, $label, 'percent', 'right');
    }

    public static function date(string $key, string $label): self
    {
        return new self($key, $label, 'date', 'left');
    }

    public function isNumeric(): bool
    {
        return in_array($this->format, ['number', 'currency', 'percent'], true);
    }

    /** Display string for a cell value (used by screen, PDF and print). */
    public function display(mixed $value): string
    {
        if ($value === null || $value === '') {
            return ReportFormat::DASH;
        }

        return match ($this->format) {
            'currency' => ReportFormat::currencyFull((float) $value),
            'percent' => ReportFormat::percent((float) $value),
            'number' => ReportFormat::number($value),
            default => (string) $value,
        };
    }
}
