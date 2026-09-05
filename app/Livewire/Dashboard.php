<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\DatePreset;
use App\Services\Dashboard\DashboardService;
use App\Services\Reports\ReportFilterResolver;
use App\Support\Reports\ReportFilterData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The executive business dashboard (`/dashboard`).
 *
 * Data is assembled by {@see DashboardService}, which reuses the M11.2
 * {@see \App\Services\Reports\ExecutiveDashboardService} for every KPI and the
 * sales / collection / inventory / attention / recent-bookings sections — all
 * M7/M8 financial truth — and adds the M13 pipeline + follow-up counts. Every
 * widget is permission-aware; a figure a user may not see is never queried.
 *
 * The route carries no permission of its own, so a user with no module access
 * still gets a valid (near-empty) dashboard rather than a 403.
 */
#[Layout('components.layouts.app')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    /** Reporting date preset ('' = the default: this month). */
    #[Url]
    public string $period = '';

    /** Sales-trend metric: 'value' | 'bookings'. */
    #[Url]
    public string $metric = 'value';

    /** @var list<string> */
    private const PERIODS = [
        DatePreset::Today->value,
        DatePreset::ThisWeek->value,
        DatePreset::ThisMonth->value,
        DatePreset::LastMonth->value,
        DatePreset::ThisQuarter->value,
        DatePreset::ThisYear->value,
    ];

    public function setMetric(string $metric): void
    {
        $this->metric = $metric === 'bookings' ? 'bookings' : 'value';
    }

    private function filters(ReportFilterResolver $resolver): ReportFilterData
    {
        $preset = in_array($this->period, self::PERIODS, true) ? $this->period : null;

        if ($preset === null) {
            $this->period = '';

            return ReportFilterData::default();
        }

        try {
            return $resolver->resolve(['preset' => $preset], auth()->user());
        } catch (ValidationException) {
            $this->period = '';

            return ReportFilterData::default();
        }
    }

    /**
     * Compact platform health — a status dot only. Deliberately exposes no
     * hostnames, database names, drivers or versions.
     *
     * @return list<array{label: string, ok: bool}>
     */
    public function health(): array
    {
        return [
            $this->probe('MySQL', fn () => DB::connection()->getPdo()->query('SELECT 1')),
            $this->probe('Redis', fn () => Redis::connection()->ping()),
            $this->probe('Cache', function () {
                Cache::store()->put('dashboard:health', 1, 10);

                return Cache::store()->get('dashboard:health') === 1;
            }),
            $this->probe('Queue', fn () => Queue::size() >= 0),
        ];
    }

    /**
     * @param  callable(): mixed  $callback
     * @return array{label: string, ok: bool}
     */
    private function probe(string $label, callable $callback): array
    {
        try {
            $callback();

            return ['label' => $label, 'ok' => true];
        } catch (\Throwable $e) {
            report($e);

            return ['label' => $label, 'ok' => false];
        }
    }

    public function render(ReportFilterResolver $resolver): View
    {
        $user = auth()->user();
        $filters = $this->filters($resolver);
        $metric = $this->metric === 'bookings' ? 'bookings' : 'value';

        return view('livewire.dashboard', [
            'data' => app(DashboardService::class)->build($filters, $user, $metric),
            'filters' => $filters,
            'metric' => $metric,
            'period' => $this->period,
            'periodOptions' => DatePreset::cases(),
            'periods' => self::PERIODS,
            'health' => $this->health(),
        ]);
    }
}
