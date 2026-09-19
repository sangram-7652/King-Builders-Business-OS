<?php

declare(strict_types=1);

use App\Enums\DatePreset;
use App\Enums\ReportType;
use App\Models\Block;
use App\Models\Booking;
use App\Models\BookingBuyer;
use App\Models\Buyer;
use App\Models\Payment;
use App\Models\Plot;
use App\Models\Project;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Reports\ExecutiveDashboardService;
use App\Services\Reports\InventoryReportService;
use App\Services\Reports\MisReportService;
use App\Services\Reports\PaymentsAnalytics;
use App\Services\Reports\ReportExportBuilder;
use App\Services\Reports\SalesReportService;
use App\Support\Reports\InventoryFilters;
use App\Support\Reports\ReportFilterData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    $this->travelTo(Carbon::parse('2026-09-15 09:00:00', 'UTC'));
});

// ===========================================================================
//  9 · DATE PRESETS — start/end boundaries in the app timezone
// ===========================================================================

it('resolves every preset to an inclusive, day-aligned window in the app timezone', function () {
    $now = CarbonImmutable::parse('2026-05-20 13:30:00', config('app.timezone')); // a Wednesday, Q2

    $cases = [
        [DatePreset::Today, '2026-05-20', '2026-05-20'],
        [DatePreset::Yesterday, '2026-05-19', '2026-05-19'],
        [DatePreset::ThisWeek, '2026-05-18', '2026-05-24'],   // Mon–Sun
        [DatePreset::ThisMonth, '2026-05-01', '2026-05-31'],
        [DatePreset::LastMonth, '2026-04-01', '2026-04-30'],
        [DatePreset::ThisQuarter, '2026-04-01', '2026-06-30'],
        [DatePreset::ThisYear, '2026-01-01', '2026-12-31'],
    ];

    foreach ($cases as [$preset, $from, $to]) {
        [$f, $t] = $preset->resolveRange($now);
        expect($f->toDateString())->toBe($from)
            ->and($f->format('H:i:s'))->toBe('00:00:00')
            ->and($t->toDateString())->toBe($to)
            ->and($t->format('H:i:s'))->toBe('23:59:59');
    }
});

// ===========================================================================
//  10 · FILTER SECURITY — invalid enums + foreign ids rejected everywhere
// ===========================================================================

dataset('every report route', ['reports.overview', 'reports.sales', 'reports.inventory', 'reports.mis']);

it('rejects an invalid enum value for every status filter', function (string $route) {
    $me = makeUser(permissions: ['reports.view']);

    foreach (['booking_status', 'payment_status', 'plot_status'] as $field) {
        $this->actingAs($me)->getJson(route($route, [$field => 'not-a-real-status']))
            ->assertStatus(422)->assertJsonValidationErrors($field);
    }
})->with('every report route');

it('rejects an inverted custom date range', function () {
    $this->actingAs(makeUser(permissions: ['reports.view']))
        ->getJson(route('reports.sales', ['from' => '2026-06-30', 'to' => '2026-06-01']))
        ->assertStatus(422)->assertJsonValidationErrors('to');
});

it('accepts a fully-combined valid filter set', function () {
    $p = Project::factory()->create();
    $b = Block::factory()->create(['project_id' => $p->id]);
    $me = makeUser(permissions: ['reports.view']);

    $this->actingAs($me)->get(route('reports.mis', [
        'preset' => 'this_year', 'project_id' => $p->id, 'block_id' => $b->id,
        'salesperson_id' => $me->id, 'booking_status' => 'confirmed',
        'payment_status' => 'success', 'plot_status' => 'available',
    ]))->assertOk();
});

// ===========================================================================
//  4 · IDOR — export route rejects foreign ids and unknown type/format
// ===========================================================================

it('rejects foreign ids and unknown type/format on the export route', function () {
    $me = makeUser(permissions: ['reports.view', 'reports.export']);
    $foreignBlock = Block::factory()->create(['project_id' => Project::factory()->create()->id]);
    $p = Project::factory()->create();

    $this->actingAs($me)->getJson(route('reports.export', ['type' => 'mis', 'format' => 'csv', 'project_id' => $p->id, 'block_id' => $foreignBlock->id]))
        ->assertStatus(422)->assertJsonValidationErrors('block_id');

    $this->actingAs($me)->getJson(route('reports.export', ['type' => 'mis', 'format' => 'csv', 'salesperson_id' => 999999]))
        ->assertStatus(422)->assertJsonValidationErrors('salesperson_id');

    $this->actingAs($me)->get('/reports/overview/export/csv')->assertNotFound();   // overview not exportable
    $this->actingAs($me)->get('/reports/mis/export/tsv')->assertNotFound();
});

// ===========================================================================
//  3 · DATA ISOLATION — a project filter never leaks another project's data
// ===========================================================================

it('never leaks another project\'s figures through the project filter (report + export)', function () {
    $w = hardWorld();

    // Alpha-scoped MIS: only Alpha figures
    $mis = app(MisReportService::class)->build(
        new ReportFilterData(from: CarbonImmutable::parse('2026-09-01')->startOfDay(), to: CarbonImmutable::parse('2026-09-30')->endOfDay(), preset: DatePreset::Custom, projectId: $w['alpha']->id),
        makeUser(permissions: ['reports.view']),
    );
    $projects = collect($mis->projects)->pluck('project');
    expect($projects)->toContain('Alpha Estate')->not->toContain('Beta Park');

    // Alpha-scoped CSV export: "Beta" appears nowhere
    $body = $this->actingAs(makeUser(permissions: ['reports.view', 'reports.export']))
        ->get(route('reports.export', ['type' => 'mis', 'format' => 'csv', 'project_id' => $w['alpha']->id, 'preset' => 'this_year']))
        ->streamedContent();
    expect($body)->toContain('Alpha Estate')->not->toContain('Beta Park');
});

// ===========================================================================
//  6 · SENSITIVE DATA — never in a report screen or export
// ===========================================================================

it('exposes no reference numbers, notes or file paths in reports or exports', function () {
    $w = hardWorld();
    // a payment carrying a reference number + a booking with an internal note
    Payment::query()->first()?->update(['reference_number' => 'SECRET-REF-12345', 'notes' => 'internal only note']);
    Booking::query()->first()->update(['notes' => 'CONFIDENTIAL booking note']);

    $viewer = makeUser(permissions: ['reports.view', 'reports.export', 'projects.view', 'plots.view', 'buyers.view', 'bookings.view', 'payments.view']);

    foreach (['reports.overview', 'reports.sales', 'reports.inventory', 'reports.mis'] as $route) {
        $html = $this->actingAs($viewer)->get(route($route, ['preset' => 'this_year']))->assertOk()->getContent();
        expect($html)->not->toContain('SECRET-REF-12345')
            ->not->toContain('CONFIDENTIAL booking note')
            ->not->toContain('internal only note')
            ->not->toContain(storage_path());
    }

    foreach ([ReportType::Mis, ReportType::Sales, ReportType::Inventory] as $type) {
        $body = $this->actingAs($viewer)->get(route('reports.export', ['type' => $type->value, 'format' => 'csv', 'preset' => 'this_year']))->streamedContent();
        expect($body)->not->toContain('SECRET-REF-12345')->not->toContain('CONFIDENTIAL')->not->toContain('internal only note');
    }
});

// ===========================================================================
//  7 · FINANCIAL CORRECTNESS — outstanding is M7 truth on every surface
// ===========================================================================

it('shows the identical M7 outstanding on the dashboard and MIS', function () {
    hardWorld();
    $f = new ReportFilterData(from: CarbonImmutable::parse('2026-09-01')->startOfDay(), to: CarbonImmutable::parse('2026-09-30')->endOfDay(), preset: DatePreset::Custom);
    $user = makeUser(permissions: ['reports.view']);

    $m7 = app(PaymentsAnalytics::class)->kpis($f)['outstanding'];

    $dash = app(ExecutiveDashboardService::class)->build($f, $user);
    $mis = app(MisReportService::class)->build($f, $user);

    expect($dash->kpi('outstanding')->value)->toBe($m7)
        ->and($mis->kpi('outstanding')->value)->toBe($m7)
        ->and($m7)->toBeGreaterThan(0.0);
});

it('scopes the collected KPI to the period with a real previous delta', function () {
    $s = confirmedBookingScenario('1000000');
    // ₹200k collected in August, ₹500k in September
    payIn($s['booking'], $s['actor'], '200000', '2026-08-20');
    payIn($s['booking'], $s['actor'], '500000', '2026-09-10');

    $sept = new ReportFilterData(from: CarbonImmutable::parse('2026-09-01')->startOfDay(), to: CarbonImmutable::parse('2026-09-30')->endOfDay(), preset: DatePreset::Custom);
    $dash = app(ExecutiveDashboardService::class)->build($sept, makeUser(permissions: ['reports.view']));

    $kpi = $dash->kpi('total_collected');
    expect($kpi->value)->toBe(500_000.0)          // September only, not 700k all-time
        ->and($kpi->previous)->toBe(200_000.0)     // August (the previous month)
        ->and($kpi->hasComparison())->toBeTrue();
});

// ===========================================================================
//  12 · QUERY BUDGET — bounded, and does not grow with data volume
// ===========================================================================

it('keeps a bounded query count that does not scale with data volume (no N+1)', function () {
    $user = makeUser(permissions: ['reports.view', 'reports.export']);

    hardWorld();
    $oneX = countQueries(fn () => buildAllReports($user));

    hardWorld();
    hardWorld();
    $threeX = countQueries(fn () => buildAllReports($user));

    hardWorld();
    hardWorld();
    hardWorld();
    $sixX = countQueries(fn () => buildAllReports($user));

    // The per-render query count is flat once data exists — 3x → 6x adds
    // nothing, and 1x differs only by a tiny constant (a couple of
    // empty-vs-populated branches). N+1 would show tens-to-hundreds of extra
    // queries here. Covers 5 report services + 4 export builds.
    expect($sixX)->toBe($threeX)                 // doubling the data again: identical
        ->and(abs($threeX - $oneX))->toBeLessThan(5)
        ->and($oneX)->toBeLessThan(220);
});

// ===========================================================================
//  18 · READ-ONLY — a report/export mutates nothing but the audit row
// ===========================================================================

it('is read-only — an export writes only its own audit row', function () {
    hardWorld();
    $before = [
        'bookings' => Booking::count(),
        'payments' => Payment::count(),
    ];

    $this->actingAs(makeUser(permissions: ['reports.view', 'reports.export']))
        ->get(route('reports.export', ['type' => 'mis', 'format' => 'xlsx', 'preset' => 'this_year']))->assertOk();

    expect(Booking::count())->toBe($before['bookings'])
        ->and(Payment::count())->toBe($before['payments'])
        ->and(ReportExport::count())->toBe(1);
});

// ===========================================================================
//  15 · ERROR HANDLING — empty state, no internals
// ===========================================================================

it('renders every report cleanly with zero data and leaks no internals', function (string $route) {
    $res = $this->actingAs(makeUser(permissions: ['reports.view']))->get(route($route))->assertOk();
    $html = $res->getContent();
    expect($html)->not->toContain('Unavailable')
        ->not->toContain('SQLSTATE')
        ->not->toContain('Stack trace')
        ->not->toContain(base_path());
})->with('every report route');

// ===========================================================================
//  5 · AUTHORIZATION — export controls hidden from users without reports.export
// ===========================================================================

it('hides every export control from a user without reports.export', function () {
    hardWorld();
    $noExport = makeUser(permissions: ['reports.view']);
    $withExport = makeUser(permissions: ['reports.view', 'reports.export']);

    foreach (['reports.sales', 'reports.inventory', 'reports.mis'] as $route) {
        $hidden = $this->actingAs($noExport)->get(route($route))->assertOk()->getContent();
        expect($hidden)->not->toContain('/export/csv')
            ->not->toContain('/export/xlsx')
            ->not->toContain('/export/pdf');

        $shown = $this->actingAs($withExport)->get(route($route))->assertOk()->getContent();
        expect($shown)->toContain('/export/csv');
    }

    // and the endpoint itself is closed to them
    $this->actingAs($noExport)->get(route('reports.export', ['type' => 'mis', 'format' => 'csv']))->assertForbidden();
});

// ---------------------------------------------------------------------------
//  helpers
// ---------------------------------------------------------------------------

/** @return array<string, mixed> */
function hardWorld(): array
{
    $actor = User::factory()->create();
    $alpha = Project::factory()->create(['name' => 'Alpha Estate']);
    $aBlock = Block::factory()->create(['project_id' => $alpha->id]);
    $beta = Project::factory()->create(['name' => 'Beta Park']);
    $bBlock = Block::factory()->create(['project_id' => $beta->id]);

    $mk = function (Project $p, Block $b, string $amount, string $date, ?array $pay = null) use ($actor) {
        $plot = Plot::factory()->create(['project_id' => $p->id, 'block_id' => $b->id, 'status' => 'booked']);
        $booking = Booking::factory()->confirmed()->forPlot($plot)->create([
            'created_by' => $actor->id, 'booking_date' => $date,
            'final_amount' => $amount, 'base_amount' => $amount, 'subtotal' => $amount,
        ]);
        $buyer = Buyer::factory()->create(['status' => 'active']);
        BookingBuyer::factory()->create(['booking_id' => $booking->id, 'buyer_id' => $buyer->id, 'is_primary' => true, 'ownership_percentage' => 100]);
        if ($pay !== null) {
            payIn($booking, $actor, $pay[0], $pay[1]);
        }

        return $booking;
    };

    $mk($alpha, $aBlock, '1000000', '2026-09-05', ['300000', '2026-09-08']);

    $mk($beta, $bBlock, '2000000', '2026-09-06');

    return compact('actor', 'alpha', 'beta', 'aBlock', 'bBlock');
}

function countQueries(Closure $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $n = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $n;
}

function buildAllReports(User $u): void
{
    $f = ReportFilterData::default();
    app(ExecutiveDashboardService::class)->build($f, $u);
    app(SalesReportService::class)->build($f, $u);
    app(InventoryReportService::class)->build($f, new InventoryFilters, $u);
    app(MisReportService::class)->build($f, $u);
    foreach (ReportType::cases() as $t) {
        app(ReportExportBuilder::class)->build($t, $f, $u);
    }
}
