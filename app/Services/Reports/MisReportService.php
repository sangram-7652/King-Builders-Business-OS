<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\User;
use App\Support\Reports\Kpi;
use App\Support\Reports\MisReportData;
use App\Support\Reports\ReportFilterData;
use Throwable;

/**
 * Assembles the Management MIS screen (M11.5).
 *
 * Pure orchestration over {@see MisAnalytics}. Each section is wrapped so a
 * failing query records an error and yields an empty result — the rest of the
 * page still renders. No caching: the page is ~20 bounded aggregate queries on
 * indexed columns; if that changes, this method is the single wrap point and a
 * cache key MUST combine the user id with the full filter set.
 *
 * The same {@see MisAnalytics} instance feeds
 * {@see ReportExportBuilder}, so an exported figure can never differ from the
 * screen.
 */
class MisReportService
{
    public function __construct(private readonly MisAnalytics $analytics) {}

    public function build(ReportFilterData $filters, User $user): MisReportData
    {
        /** @var array<string, string> $errors */
        $errors = [];
        $safe = function (string $section, callable $fn, mixed $fallback) use (&$errors) {
            try {
                return $fn();
            } catch (Throwable $e) {
                report($e);
                $errors[$section] = 'Could not load '.lcfirst($section).'.';

                return $fallback;
            }
        };

        $k = $safe('Management KPIs', fn () => $this->analytics->kpis($filters), []);
        $daily = $safe('Daily MIS', fn () => $this->analytics->daily($filters), ['rows' => [], 'truncated' => false]);
        $monthly = $safe('Monthly MIS', fn () => $this->analytics->monthly($filters), []);
        $projects = $safe('Project MIS', fn () => $this->analytics->projects($filters), []);

        $salespeople = $safe('Salesperson MIS', fn () => $this->analytics->salespeople($filters), []);

        $err = fn (string $s) => $errors[$s] ?? null;
        $kErr = $err('Management KPIs');

        $kpis = [
            new Kpi('total_projects', 'Total projects', $k['total_projects'] ?? null, 'number', error: $kErr),
            new Kpi('total_plots', 'Total plots', $k['total_plots'] ?? null, 'number', error: $kErr),
            new Kpi('available', 'Available', $k['available'] ?? null, 'number', error: $kErr),
            new Kpi('booked', 'Booked', $k['booked'] ?? null, 'number', error: $kErr),
            new Kpi('registered', 'Registered', $k['registered'] ?? null, 'number', error: $kErr),
            new Kpi('possession_completed', 'Possession completed', $k['possession_completed'] ?? null, 'number', error: $kErr),

            new Kpi('total_bookings', 'Total bookings', $k['total_bookings'] ?? null, 'number', error: $kErr, hint: 'Confirmed in period'),
            new Kpi('booking_value', 'Booking value', $k['booking_value'] ?? null, 'currency', error: $kErr, hint: 'Confirmed final amount'),
            new Kpi('collected', 'Collected', $k['collected'] ?? null, 'currency', error: $kErr),
            new Kpi('outstanding', 'Outstanding', $k['outstanding'] ?? null, 'currency', error: $kErr, hint: 'Final amount − successful payments'),

            new Kpi('registry_pending', 'Registry pending', $k['registry_pending'] ?? null, 'number', error: $kErr),
            new Kpi('possession_pending', 'Possession pending', $k['possession_pending'] ?? null, 'number', error: $kErr),
            new Kpi('transfer_pending', 'Transfer pending', $k['transfer_pending'] ?? null, 'number', error: $kErr),
            new Kpi('documents_pending', 'Documents pending verification', $k['documents_pending'] ?? null, 'number', error: $kErr),
        ];

        return new MisReportData(
            kpis: $kpis,
            daily: $daily['rows'],
            dailyTruncated: $daily['truncated'],
            monthly: $monthly,
            projects: $projects,
            salespeople: $salespeople,
            filters: $filters,
            generatedAt: now()->toIso8601String(),
            errors: $errors,
        );
    }
}
