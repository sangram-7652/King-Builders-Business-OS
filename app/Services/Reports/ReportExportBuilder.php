<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\AgingBucket;
use App\Enums\PlotStatus;
use App\Enums\ReportType;
use App\Models\Block;
use App\Models\Project;
use App\Models\User;
use App\Support\Reports\ReportColumn;
use App\Support\Reports\ReportExportPayload;
use App\Support\Reports\ReportFilterData;
use App\Support\Reports\ReportTable;
use Carbon\CarbonImmutable;

/**
 * Turns a {@see ReportType} + resolved {@see ReportFilterData} into a
 * {@see ReportExportPayload} (M11.5).
 *
 * Every table is built from the *same* analytics services the on-screen report
 * uses — there is no export-only query path, so an exported figure can never
 * drift from the screen.
 *
 * All figures are M7 truth (outstanding = booking final amount − successful
 * payments) — never a separate engine.
 */
class ReportExportBuilder
{
    public function __construct(
        private readonly MisAnalytics $mis,
        private readonly SalesAnalytics $sales,
        private readonly InventoryAnalytics $inventory,
    ) {}

    public function build(ReportType $type, ReportFilterData $filters, User $user): ReportExportPayload
    {
        $tables = match ($type) {
            ReportType::Mis => $this->misTables($filters),
            ReportType::Sales => $this->salesTables($filters),
            ReportType::Inventory => $this->inventoryTables($filters),
        };

        return new ReportExportPayload(
            type: $type,
            title: $type->title(),
            periodLabel: $filters->periodLabel().' ('.$filters->from->format('d M Y').' – '.$filters->to->format('d M Y').')',
            tables: $tables,
            filterSummary: $this->filterSummary($filters),
            generatedAt: CarbonImmutable::now(config('app.timezone')),
            generatedBy: (string) $user->name,
        );
    }

    // -- MIS -----------------------------------------------------------------

    /** @return list<ReportTable> */
    private function misTables(ReportFilterData $filters): array
    {
        $daily = $this->mis->daily($filters);
        $projects = $this->mis->projects($filters);

        return [
            $this->metricTable('kpis', 'Management KPIs', $this->mis->kpis($filters), [
                'total_projects' => ['Total projects', 'number'],
                'total_plots' => ['Total plots', 'number'],
                'available' => ['Available', 'number'],
                'booked' => ['Booked', 'number'],
                'registered' => ['Registered', 'number'],
                'possession_completed' => ['Possession completed', 'number'],
                'total_bookings' => ['Total bookings', 'number'],
                'booking_value' => ['Booking value', 'currency'],
                'collected' => ['Collected', 'currency'],
                'outstanding' => ['Outstanding', 'currency'],
                'registry_pending' => ['Registry pending', 'number'],
                'possession_pending' => ['Possession pending', 'number'],
                'transfer_pending' => ['Transfer pending', 'number'],
                'documents_pending' => ['Documents pending verification', 'number'],
            ]),
            new ReportTable(
                'daily', 'Daily MIS',
                [
                    ReportColumn::date('date', 'Date'),
                    ReportColumn::number('bookings', 'Bookings'),
                    ReportColumn::currency('booking_value', 'Booking value'),
                    ReportColumn::currency('collected', 'Collected'),
                ],
                $daily['rows'],
                $this->sumRow($daily['rows'], ['bookings', 'booking_value', 'collected'], 'date', 'Total'),
                $daily['truncated'] ? 'Showing the most recent '.MisAnalytics::MAX_DAILY_ROWS.' days of the selected window.' : null,
            ),
            new ReportTable(
                'monthly', 'Monthly MIS',
                [
                    ReportColumn::text('month', 'Month'),
                    ReportColumn::number('bookings', 'Bookings'),
                    ReportColumn::currency('booking_value', 'Booking value'),
                    ReportColumn::currency('collected', 'Collected'),
                ],
                $this->mis->monthly($filters),
            ),
            new ReportTable(
                'projects', 'Project MIS',
                [
                    ReportColumn::text('project', 'Project'),
                    ReportColumn::number('plots', 'Plots'),
                    ReportColumn::number('booked', 'Booked'),
                    ReportColumn::number('available', 'Available'),
                    ReportColumn::currency('sales_value', 'Sales value'),
                    ReportColumn::currency('collected', 'Collected'),
                    ReportColumn::currency('outstanding', 'Outstanding'),
                ],
                $projects,
                $this->sumRow($projects, ['plots', 'booked', 'available', 'sales_value', 'collected', 'outstanding'], 'project', 'Total'),
            ),
            new ReportTable(
                'salespeople', 'Salesperson MIS',
                [
                    ReportColumn::text('name', 'Salesperson'),
                    ReportColumn::number('bookings', 'Bookings'),
                    ReportColumn::currency('booking_value', 'Booking value'),
                    ReportColumn::currency('collected', 'Collected'),
                    ReportColumn::currency('outstanding', 'Outstanding'),
                ],
                $this->mis->salespeople($filters),
            ),
        ];
    }

    // -- Sales -------------------------------------------------------------

    /** @return list<ReportTable> */
    private function salesTables(ReportFilterData $filters): array
    {
        $ps = $this->sales->projectSales($filters);

        return [
            $this->metricTable('kpis', 'Sales KPIs', $this->sales->salesKpis($filters), [
                'bookings' => ['Bookings', 'number'],
                'value' => ['Booking value', 'currency'],
                'average' => ['Average booking value', 'currency'],
            ]),
            new ReportTable('projects', 'Project-wise sales', [
                ReportColumn::text('project', 'Project'),
                ReportColumn::number('bookings', 'Bookings'),
                ReportColumn::currency('value', 'Value'),
                ReportColumn::currency('average', 'Avg value'),
                ReportColumn::percent('sold_pct', 'Sold %'),
            ], $ps, $this->sumRow($ps, ['bookings', 'value'], 'project', 'Total')),
            new ReportTable('blocks', 'Block-wise sales', [
                ReportColumn::text('block', 'Block'),
                ReportColumn::text('project', 'Project'),
                ReportColumn::number('total', 'Inventory'),
                ReportColumn::number('booked', 'Booked'),
                ReportColumn::number('available', 'Available'),
                ReportColumn::currency('value', 'Value'),
                ReportColumn::percent('sold_pct', 'Sold %'),
            ], $this->sales->blockSales($filters)),
            new ReportTable('salespeople', 'Salesperson performance', [
                ReportColumn::text('name', 'Salesperson'),
                ReportColumn::number('bookings', 'Bookings'),
                ReportColumn::currency('value', 'Value'),
            ], $this->sales->salespersonPerformance($filters)),
        ];
    }

    // -- Inventory -------------------------------------------------------

    /** @return list<ReportTable> */
    private function inventoryTables(ReportFilterData $filters): array
    {
        $dist = $this->inventory->statusDistribution($filters);
        $ageing = $this->inventory->ageing($filters);
        $pi = $this->inventory->projectInventory($filters);

        return [
            $this->metricTable('kpis', 'Inventory KPIs', $this->inventory->inventoryKpis($filters), [
                'total' => ['Total plots', 'number'],
                'available' => ['Available', 'number'],
                'booked' => ['Booked', 'number'],
                'registered' => ['Registered', 'number'],
                'possession' => ['Possession completed', 'number'],
            ]),
            new ReportTable('status', 'Status distribution', [
                ReportColumn::text('status', 'Status'),
                ReportColumn::number('count', 'Plots'),
            ], collect(PlotStatus::cases())->map(fn (PlotStatus $s) => [
                'status' => $s->label(), 'count' => $dist[$s->value] ?? 0,
            ])->all()),
            new ReportTable('projects', 'Project inventory', [
                ReportColumn::text('project', 'Project'),
                ReportColumn::number('total', 'Total'),
                ReportColumn::number('available', 'Available'),
                ReportColumn::number('booked', 'Booked'),
                ReportColumn::number('registered', 'Registered'),
                ReportColumn::number('possession', 'Possession'),
                ReportColumn::percent('sold_pct', 'Sold %'),
            ], $pi, $this->sumRow($pi, ['total', 'available', 'booked', 'registered', 'possession'], 'project', 'Total')),
            new ReportTable('blocks', 'Block inventory', [
                ReportColumn::text('block', 'Block'),
                ReportColumn::text('project', 'Project'),
                ReportColumn::number('total', 'Total'),
                ReportColumn::number('available', 'Available'),
                ReportColumn::number('booked', 'Booked'),
                ReportColumn::percent('sold_pct', 'Sold %'),
            ], $this->inventory->blockInventory($filters)),
            new ReportTable('ageing', 'Inventory ageing', [
                ReportColumn::text('bucket', 'Bucket'),
                ReportColumn::number('count', 'Available plots'),
            ], collect(AgingBucket::cases())->map(fn (AgingBucket $b) => [
                'bucket' => $b->label(), 'count' => $ageing[$b->value] ?? 0,
            ])->all()),
            new ReportTable('price', 'Price bands', [
                ReportColumn::text('band', 'Band'),
                ReportColumn::number('bookings', 'Bookings'),
                ReportColumn::currency('value', 'Value'),
                ReportColumn::currency('average', 'Avg price'),
            ], $this->inventory->priceBands($filters)),
            new ReportTable('size', 'Size bands', [
                ReportColumn::text('band', 'Size'),
                ReportColumn::number('total', 'Total'),
                ReportColumn::number('available', 'Available'),
                ReportColumn::number('booked', 'Booked'),
            ], $this->inventory->sizeBands($filters)),
        ];
    }

    // -- helpers ---------------------------------------------------------

    /**
     * A two-column metric / value table from an associative array. Each value
     * is rendered to its display string here so the exporter stays format-blind.
     *
     * @param  array<string, int|float|string|null>  $data
     * @param  array<string, array{0: string, 1: string}>  $spec  key => [label, format]; also defines order
     */
    private function metricTable(string $key, string $title, array $data, array $spec): ReportTable
    {
        $rows = [];
        foreach ($spec as $metricKey => [$label, $format]) {
            $col = match ($format) {
                'percent' => ReportColumn::percent('v', 'v'),
                'number' => ReportColumn::number('v', 'v'),
                default => ReportColumn::currency('v', 'v'),
            };
            $rows[] = ['metric' => $label, 'value' => $col->display($data[$metricKey] ?? null)];
        }

        return new ReportTable($key, $title, [
            ReportColumn::text('metric', 'Metric'),
            ReportColumn::text('value', 'Value'),
        ], $rows);
    }

    /**
     * A totals row summing the given numeric keys across `$rows`.
     *
     * @param  list<array<string, int|float|string|null>>  $rows
     * @param  list<string>  $sumKeys
     * @return array<string, int|float|string>|null
     */
    private function sumRow(array $rows, array $sumKeys, string $labelKey, string $label): ?array
    {
        if ($rows === []) {
            return null;
        }

        $totals = [$labelKey => $label];
        foreach ($sumKeys as $key) {
            $totals[$key] = round(array_sum(array_map(fn ($r) => (float) ($r[$key] ?? 0), $rows)), 2);
        }

        return $totals;
    }

    /**
     * The applied filters, as human-readable label => value pairs.
     *
     * @return array<string, string>
     */
    private function filterSummary(ReportFilterData $filters): array
    {
        $summary = ['Period' => $filters->periodLabel().' ('.$filters->from->format('d M Y').' – '.$filters->to->format('d M Y').')'];

        if ($filters->projectId !== null) {
            $summary['Project'] = (string) (Project::query()->whereKey($filters->projectId)->value('name') ?? $filters->projectId);
        }
        if ($filters->blockId !== null) {
            $summary['Block'] = (string) (Block::query()->whereKey($filters->blockId)->value('name') ?? $filters->blockId);
        }
        if ($filters->salespersonId !== null) {
            $summary['Salesperson'] = (string) (User::query()->whereKey($filters->salespersonId)->value('name') ?? $filters->salespersonId);
        }
        if ($filters->bookingStatus !== null) {
            $summary['Booking status'] = $filters->bookingStatus->label();
        }
        if ($filters->paymentStatus !== null) {
            $summary['Payment status'] = $filters->paymentStatus->label();
        }
        if ($filters->plotStatus !== null) {
            $summary['Plot status'] = $filters->plotStatus->label();
        }

        return $summary;
    }
}
