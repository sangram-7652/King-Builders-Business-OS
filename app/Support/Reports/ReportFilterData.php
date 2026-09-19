<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\BookingStatus;
use App\Enums\DatePreset;
use App\Enums\PaymentStatus;
use App\Enums\PlotStatus;
use Carbon\CarbonImmutable;

/**
 * The validated, normalised set of global report filters (M11.1).
 *
 * Immutable value object shared by every report page and by the future
 * M11.2–M11.6 query classes. It is produced only by the
 * ReportFilterResolver service — never constructed from raw request input
 * directly — so the date window is always day-aligned in the app timezone and
 * every id has been checked to exist.
 *
 * `from` is the start of its day; `to` is the end of its day (inclusive).
 */
final readonly class ReportFilterData
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public DatePreset $preset,
        public ?int $projectId = null,
        public ?int $blockId = null,
        public ?int $salespersonId = null,
        public ?BookingStatus $bookingStatus = null,
        public ?PaymentStatus $paymentStatus = null,
        public ?PlotStatus $plotStatus = null,
    ) {}

    /**
     * The default filter state: current month, no other constraints.
     */
    public static function default(): self
    {
        $preset = DatePreset::DEFAULT;
        [$from, $to] = $preset->resolveRange();

        return new self($from, $to, $preset);
    }

    public function isDefault(): bool
    {
        return $this->preset === DatePreset::DEFAULT
            && $this->projectId === null
            && $this->blockId === null
            && $this->salespersonId === null
            && $this->bookingStatus === null
            && $this->paymentStatus === null
            && $this->plotStatus === null;
    }

    /**
     * Query-string representation for links (pagination, sort, tab switches) and
     * for round-tripping the current state in the URL. Only non-default keys are
     * emitted so a clean/reset URL stays clean.
     *
     * @return array<string, string>
     */
    public function toQueryString(): array
    {
        $params = [];

        if ($this->preset === DatePreset::Custom) {
            $params['from'] = $this->from->toDateString();
            $params['to'] = $this->to->toDateString();
        } elseif ($this->preset !== DatePreset::DEFAULT) {
            $params['preset'] = $this->preset->value;
        }

        if ($this->projectId !== null) {
            $params['project_id'] = (string) $this->projectId;
        }
        if ($this->blockId !== null) {
            $params['block_id'] = (string) $this->blockId;
        }
        if ($this->salespersonId !== null) {
            $params['salesperson_id'] = (string) $this->salespersonId;
        }
        if ($this->bookingStatus !== null) {
            $params['booking_status'] = $this->bookingStatus->value;
        }
        if ($this->paymentStatus !== null) {
            $params['payment_status'] = $this->paymentStatus->value;
        }
        if ($this->plotStatus !== null) {
            $params['plot_status'] = $this->plotStatus->value;
        }

        return $params;
    }

    /**
     * Raw values keyed for pre-filling the filter form controls.
     *
     * @return array<string, string>
     */
    public function toFormValues(): array
    {
        return [
            'preset' => $this->preset->value,
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'project_id' => (string) ($this->projectId ?? ''),
            'block_id' => (string) ($this->blockId ?? ''),
            'salesperson_id' => (string) ($this->salespersonId ?? ''),
            'booking_status' => $this->bookingStatus?->value ?? '',
            'payment_status' => $this->paymentStatus?->value ?? '',
            'plot_status' => $this->plotStatus?->value ?? '',
        ];
    }

    /** A short human description of the active window, for headers / exports. */
    public function periodLabel(): string
    {
        if ($this->preset !== DatePreset::Custom) {
            return $this->preset->label();
        }

        return $this->from->isSameDay($this->to)
            ? $this->from->format('d M Y')
            : $this->from->format('d M Y').' – '.$this->to->format('d M Y');
    }
}
