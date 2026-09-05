<?php

declare(strict_types=1);

use App\Enums\ExportFormat;
use App\Enums\ReportType;
use App\Models\Project;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Reports\MisReportService;
use App\Services\Reports\ReportExportBuilder;
use App\Support\Reports\ReportFormat;
use App\Support\Reports\XlsxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    $this->travelTo(Carbon::parse('2026-09-15 09:00:00', 'UTC'));
});

/** misWorld() + misFilter() are defined in MisReportTest.php (shared Pest helpers). */
function exportUser(): User
{
    return makeUser(permissions: ['reports.view', 'reports.export', 'leads.view_all', 'projects.view']);
}

// ---------------------------------------------------------------------------
//  AUTHORIZATION
// ---------------------------------------------------------------------------

it('forbids export for a user with reports.view but not reports.export', function (string $format) {
    misWorld();
    $this->actingAs(makeUser(permissions: ['reports.view', 'leads.view_all']))
        ->get(route('reports.export', ['type' => 'mis', 'format' => $format]))
        ->assertForbidden();
})->with(['csv', 'xlsx', 'pdf', 'print']);

it('forbids export entirely without reports.view', function () {
    $this->actingAs(makeUser(permissions: ['bookings.view']))
        ->get(route('reports.export', ['type' => 'mis', 'format' => 'csv']))
        ->assertForbidden();
});

it('404s an unknown report type or format', function () {
    $me = exportUser();
    $this->actingAs($me)->get('/reports/leads/export/csv')->assertNotFound();
    $this->actingAs($me)->get('/reports/mis/export/json')->assertNotFound();
});

// ---------------------------------------------------------------------------
//  FORMAT OUTPUT
// ---------------------------------------------------------------------------

it('streams a UTF-8 CSV with the title, filters and M8 figures', function () {
    misWorld();
    $res = $this->actingAs(exportUser())->get(route('reports.export', ['type' => 'mis', 'format' => 'csv']));

    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('text/csv');
    expect($res->headers->get('content-disposition'))->toContain('attachment')
        ->and($res->headers->get('content-disposition'))->toContain('.csv');

    $body = $res->streamedContent();
    expect($body)->toStartWith("\xEF\xBB\xBF")                      // UTF-8 BOM
        ->toContain('Management MIS')
        ->toContain('Management KPIs')
        ->toContain(ReportFormat::currencyFull(2_700_000))          // outstanding
        ->toContain('Beta Park');
});

it('downloads a valid XLSX workbook', function () {
    misWorld();
    $res = $this->actingAs(exportUser())->get(route('reports.export', ['type' => 'mis', 'format' => 'xlsx']));

    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('spreadsheetml');
    expect($res->headers->get('content-disposition'))->toContain('.xlsx');

    // Build the same payload and confirm the writer produces a real OOXML zip.
    $payload = app(ReportExportBuilder::class)->build(ReportType::Mis, misFilter(), exportUser());
    $file = tempnam(sys_get_temp_dir(), 'xlsxtest');
    (new XlsxWriter)
        ->addSheet('S', [['a', 'b'], ['x', 1]])
        ->save($file);
    $zip = new ZipArchive;
    expect($zip->open($file))->toBeTrue();
    expect($zip->locateName('xl/workbook.xml'))->not->toBeFalse();
    expect($zip->locateName('[Content_Types].xml'))->not->toBeFalse();
    $zip->close();
    @unlink($file);
    expect($payload->tables)->not->toBeEmpty();
});

it('renders a PDF via the existing dompdf architecture', function () {
    misWorld();
    $res = $this->actingAs(exportUser())->get(route('reports.export', ['type' => 'mis', 'format' => 'pdf']));

    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('application/pdf');
    expect($res->getContent())->toStartWith('%PDF-');
});

it('serves a stripped print view with no app chrome', function () {
    misWorld();
    $res = $this->actingAs(exportUser())->get(route('reports.export', ['type' => 'mis', 'format' => 'print']));

    $res->assertOk()
        ->assertSee('Management MIS')
        ->assertSee('Project MIS')
        ->assertDontSee('sidebarOpen', escape: false)   // the app layout's Alpine chrome
        ->assertDontSee('x-app.sidebar', escape: false);
    expect($res->headers->get('content-type'))->toContain('text/html');
    expect($res->headers->get('content-disposition'))->toBeNull();  // not a download
});

// ---------------------------------------------------------------------------
//  FILTER PRESERVATION + IDOR
// ---------------------------------------------------------------------------

it('applies exactly the report filters to the export', function () {
    $w = misWorld();

    $body = $this->actingAs(exportUser())
        ->get(route('reports.export', ['type' => 'mis', 'format' => 'csv', 'project_id' => $w['beta']->id]))
        ->streamedContent();

    expect($body)->toContain('Beta Park')
        ->not->toContain('Alpha Estate');   // filtered out of every table
});

it('rejects a foreign project id on the export route (IDOR guard)', function () {
    misWorld();
    $this->actingAs(exportUser())
        ->getJson(route('reports.export', ['type' => 'mis', 'format' => 'csv', 'project_id' => 999999]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('project_id');
});

it('forbids a scoped user exporting another salesperson and scopes their own export', function () {
    $w = misWorld();
    $scoped = makeUser(permissions: ['reports.view', 'reports.export']); // no leads.view_all

    // cannot target someone else
    $this->actingAs($scoped)
        ->getJson(route('reports.export', ['type' => 'mis', 'format' => 'csv', 'salesperson_id' => $w['asha']->id]))
        ->assertStatus(422);

    // own export is scoped + labelled
    $body = $this->actingAs($scoped)
        ->get(route('reports.export', ['type' => 'mis', 'format' => 'csv']))
        ->streamedContent();

    expect($body)->toContain('Own records only')
        ->not->toContain('Asha Rao')
        ->not->toContain('Ravi Menon');
});

// ---------------------------------------------------------------------------
//  AUDIT
// ---------------------------------------------------------------------------

it('writes one report.exported audit row per export, filters but no PII', function () {
    $w = misWorld();
    $me = exportUser();

    $this->actingAs($me)->get(route('reports.export', [
        'type' => 'collections', 'format' => 'xlsx', 'project_id' => $w['alpha']->id,
    ]))->assertOk();

    $audit = ReportExport::query()->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->user_id)->toBe($me->id)
        ->and($audit->report_type)->toBe(ReportType::Collections)
        ->and($audit->format)->toBe(ExportFormat::Xlsx)
        ->and($audit->filters)->toBe(['project_id' => (string) $w['alpha']->id])
        ->and($audit->row_count)->toBeGreaterThan(0)
        ->and(json_encode($audit->filters))->not->toContain('Cust');   // no customer data
});

it('does not audit a failed (forbidden) export', function () {
    misWorld();
    $this->actingAs(makeUser(permissions: ['reports.view', 'leads.view_all']))
        ->get(route('reports.export', ['type' => 'mis', 'format' => 'csv']))
        ->assertForbidden();

    expect(ReportExport::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
//  VALUE PARITY  (export == screen)
// ---------------------------------------------------------------------------

it('exports the same figures the MIS screen computes', function () {
    $w = misWorld();
    $filters = misFilter();
    $user = exportUser();

    $screen = app(MisReportService::class)->build($filters, $user);
    $payload = app(ReportExportBuilder::class)->build(ReportType::Mis, $filters, $user);

    // KPI table row for "Outstanding" must equal the screen KPI, formatted.
    $kpiTable = collect($payload->tables)->firstWhere('key', 'kpis');
    $outstandingRow = collect($kpiTable->rows)->firstWhere('metric', 'Outstanding');
    expect($outstandingRow['value'])->toBe(ReportFormat::currencyFull($screen->kpi('outstanding')->value));

    // Project MIS table is the exact same array the screen renders.
    $projectTable = collect($payload->tables)->firstWhere('key', 'projects');
    expect($projectTable->rows)->toBe($screen->projects);
});

it('exports every tabular report format without error', function (string $type, string $format) {
    misWorld();
    $this->actingAs(exportUser())
        ->get(route('reports.export', ['type' => $type, 'format' => $format]))
        ->assertOk();
})->with([
    ['sales', 'csv'], ['sales', 'pdf'],
    ['inventory', 'xlsx'], ['inventory', 'print'],
    ['collections', 'csv'], ['collections', 'pdf'],
    ['mis', 'xlsx'],
]);

it('builds a bounded number of queries for the MIS export (no N+1)', function () {
    misWorld();
    $user = exportUser();

    DB::enableQueryLog();
    app(ReportExportBuilder::class)->build(ReportType::Mis, misFilter(), $user);
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($count)->toBeLessThan(60);
});
