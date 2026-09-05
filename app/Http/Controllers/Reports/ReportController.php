<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Services\Reports\CollectionReportService;
use App\Services\Reports\ExecutiveDashboardService;
use App\Services\Reports\InventoryReportService;
use App\Services\Reports\MisReportService;
use App\Services\Reports\ReportFilterOptions;
use App\Services\Reports\ReportFilterResolver;
use App\Services\Reports\SalesReportService;
use App\Support\Reports\ReportFilterData;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Reporting foundation (M11.1).
 *
 * Every report page shares one flow: resolve the global filters, build the
 * access-scoped option lists, render. The analytics themselves land in
 * M11.2 (Sales) … M11.6 (MIS) — these pages currently show the resolved filter
 * state and a "coming next" placeholder. No dataset is loaded.
 *
 * Route access is gated by `permission:reports.view`; `reports.export` is
 * surfaced to the view for the (later) export controls.
 */
class ReportController extends Controller
{
    /**
     * @var array<string, array{title: string, description: string, milestone: string, route: string}>
     */
    private const REPORTS = [
        'overview' => [
            'title' => 'Executive dashboard',
            'description' => 'Cross-module KPIs, sales & collection trends and the items that need attention.',
            'milestone' => 'M11.2',
            'route' => 'reports.overview',
        ],
        'sales' => [
            'title' => 'Sales report',
            'description' => 'Bookings, value, trend and velocity — by project, block and salesperson.',
            'milestone' => 'M11.3',
            'route' => 'reports.sales',
        ],
        'inventory' => [
            'title' => 'Inventory report',
            'description' => 'Plot availability, absorption, ageing and price/size mix — by project and block.',
            'milestone' => 'M11.3',
            'route' => 'reports.inventory',
        ],
        'collections' => [
            'title' => 'Collections report',
            'description' => 'Receivable, collection, outstanding & ageing — all M7/M8 financial truth, no separate engine.',
            'milestone' => 'M11.4',
            'route' => 'reports.collections',
        ],
        'leads' => [
            'title' => 'Leads report',
            'description' => 'Lead volume, source mix, conversion and salesperson performance.',
            'milestone' => 'M11.5',
            'route' => 'reports.leads',
        ],
        'mis' => [
            'title' => 'MIS report',
            'description' => 'Consolidated management figures across inventory, sales, collections & leads — M7/M8 truth. CSV / Excel / PDF / print.',
            'milestone' => 'M11.5',
            'route' => 'reports.mis',
        ],
    ];

    public function __construct(
        private readonly ReportFilterResolver $resolver,
        private readonly ReportFilterOptions $options,
        private readonly ExecutiveDashboardService $dashboard,
        private readonly SalesReportService $salesReport,
        private readonly InventoryReportService $inventoryReport,
        private readonly CollectionReportService $collectionReport,
        private readonly MisReportService $misReport,
    ) {}

    /**
     * The executive dashboard (M11.2) — the "Overview" report.
     */
    public function overview(Request $request): View
    {
        $user = $request->user();
        $filters = $this->resolver->resolve($request->query(), $user);

        return view('reports.overview', $this->common($request, 'overview', $filters) + [
            'dashboard' => $this->dashboard->build($filters, $user, (string) $request->query('metric', 'value')),
        ]);
    }

    /**
     * The sales report (M11.3).
     */
    public function sales(Request $request): View
    {
        $user = $request->user();
        $filters = $this->resolver->resolve($request->query(), $user);

        return view('reports.sales', $this->common($request, 'sales', $filters) + [
            'sales' => $this->salesReport->build(
                $filters,
                $user,
                (string) $request->query('metric', 'value'),
                (string) $request->query('sp_sort', 'value'),
            ),
        ]);
    }

    /**
     * The inventory report (M11.3).
     */
    public function inventory(Request $request): View
    {
        $user = $request->user();
        $filters = $this->resolver->resolve($request->query(), $user);
        $extras = $this->resolver->resolveInventoryExtras($request->query());

        return view('reports.inventory', $this->common($request, 'inventory', $filters) + [
            'extras' => $extras,
            'inventory' => $this->inventoryReport->build($filters, $extras, $user),
        ]);
    }

    /**
     * The collections report (M11.4).
     */
    public function collections(Request $request): View
    {
        $user = $request->user();
        $filters = $this->resolver->resolve($request->query(), $user);
        $extras = $this->resolver->resolveCollectionExtras($request->query());

        return view('reports.collections', $this->common($request, 'collections', $filters) + [
            'extras' => $extras,
            'collections' => $this->collectionReport->build(
                $filters,
                $extras,
                $user,
                (string) $request->query('recovery_sort', 'days_overdue'),
            ),
        ]);
    }

    public function leads(Request $request): View
    {
        return $this->render($request, 'leads');
    }

    /**
     * The Management MIS report (M11.5).
     */
    public function mis(Request $request): View
    {
        $user = $request->user();
        $filters = $this->resolver->resolve($request->query(), $user);

        return view('reports.mis', $this->common($request, 'mis', $filters) + [
            'mis' => $this->misReport->build($filters, $user),
        ]);
    }

    private function render(Request $request, string $key): View
    {
        $user = $request->user();
        $filters = $this->resolver->resolve($request->query(), $user);

        return view('reports.show', $this->common($request, $key, $filters));
    }

    /**
     * The view data every report page shares.
     *
     * @return array<string, mixed>
     */
    private function common(Request $request, string $key, ReportFilterData $filters): array
    {
        $user = $request->user();

        return [
            'report' => $key,
            'meta' => self::REPORTS[$key],
            'reports' => self::REPORTS,
            'filters' => $filters,
            'options' => $this->options->for($user, $filters->projectId),
            'canExport' => $user->can(Permission::ReportsExport->value),
        ];
    }
}
