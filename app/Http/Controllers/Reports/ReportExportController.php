<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Enums\ExportFormat;
use App\Enums\ReportType;
use App\Http\Controllers\Controller;
use App\Models\ReportExport;
use App\Services\Reports\Export\ReportExportManager;
use App\Services\Reports\ReportExportBuilder;
use App\Services\Reports\ReportFilterResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Report export endpoint (M11.5).
 *
 * `GET /reports/{type}/export/{format}` — gated by BOTH `reports.view` (the
 * group) and `reports.export` (this route). Filters are resolved through the
 * exact same {@see ReportFilterResolver} the screen uses, so every id is
 * validated against real rows (foreign project / block / salesperson → 422) and
 * a scoped user cannot export another salesperson's figures.
 *
 * Every successful export writes one `report_exports` audit row (who / report /
 * format / resolved filters / row count) — no customer or payment data in the
 * payload.
 */
class ReportExportController extends Controller
{
    public function __construct(
        private readonly ReportFilterResolver $resolver,
        private readonly ReportExportBuilder $builder,
        private readonly ReportExportManager $manager,
    ) {}

    public function __invoke(Request $request, string $type, string $format): Response
    {
        $reportType = ReportType::tryFrom($type) ?? abort(404);
        $exportFormat = ExportFormat::tryFrom($format) ?? abort(404);

        $user = $request->user();
        $filters = $this->resolver->resolve($request->query(), $user);

        $payload = $this->builder->build($reportType, $filters, $user);
        $rowCount = array_sum(array_map(fn ($t) => $t->count(), $payload->tables));

        ReportExport::create([
            'user_id' => $user->getKey(),
            'report_type' => $reportType,
            'format' => $exportFormat,
            'filters' => $filters->toQueryString(),
            'row_count' => $rowCount,
            'created_at' => now(),
        ]);

        Log::info('report.exported', [
            'user_id' => $user->getKey(),
            'report_type' => $reportType->value,
            'format' => $exportFormat->value,
            'rows' => $rowCount,
        ]);

        return $this->manager->for($exportFormat)->export($payload);
    }
}
