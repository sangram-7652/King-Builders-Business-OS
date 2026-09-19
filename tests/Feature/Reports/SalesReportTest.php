<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\DatePreset;
use App\Enums\PlotStatus;
use App\Models\Block;
use App\Models\Booking;
use App\Models\Plot;
use App\Models\Project;
use App\Models\User;
use App\Services\Reports\SalesAnalytics;
use App\Services\Reports\SalesReportService;
use App\Support\Reports\ReportFilterData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    $this->travelTo(Carbon::parse('2026-06-15 09:00:00', 'UTC'));
});

/** @return array<string, mixed> */
function salesWorld(): array
{
    $asha = User::factory()->create(['name' => 'Asha Rao']);
    $ravi = User::factory()->create(['name' => 'Ravi Kumar']);

    $pA = Project::factory()->create(['name' => 'Green Meadows']);
    $bA1 = Block::factory()->create(['project_id' => $pA->id, 'name' => 'Block A1']);
    $bA2 = Block::factory()->create(['project_id' => $pA->id, 'name' => 'Block A2']);
    $pB = Project::factory()->create(['name' => 'Blue Ridge']);
    $bB1 = Block::factory()->create(['project_id' => $pB->id, 'name' => 'Block B1']);

    // Inventory for sold-% maths
    Plot::factory()->count(6)->create(['project_id' => $pA->id, 'block_id' => $bA1->id, 'status' => PlotStatus::Available->value]);
    Plot::factory()->count(4)->create(['project_id' => $pA->id, 'block_id' => $bA1->id, 'status' => PlotStatus::Booked->value]);
    Plot::factory()->count(3)->create(['project_id' => $pA->id, 'block_id' => $bA2->id, 'status' => PlotStatus::Available->value]);
    Plot::factory()->count(2)->create(['project_id' => $pB->id, 'block_id' => $bB1->id, 'status' => PlotStatus::Available->value]);
    Plot::factory()->count(3)->create(['project_id' => $pB->id, 'block_id' => $bB1->id, 'status' => PlotStatus::Sold->value]);

    $mk = function (Project $p, Block $b, string $status, string $date, string $amount, User $sp) {
        $plot = Plot::factory()->create(['project_id' => $p->id, 'block_id' => $b->id, 'status' => PlotStatus::Booked->value]);

        return Booking::factory()->status(BookingStatus::from($status))->create([
            'project_id' => $p->id, 'block_id' => $b->id, 'plot_id' => $plot->id,
            'created_by' => $sp->id, 'booking_date' => $date,
            'final_amount' => $amount, 'base_amount' => $amount, 'subtotal' => $amount,
            'confirmed_at' => $status === 'confirmed' ? $date : null,
        ]);
    };

    $mk($pA, $bA1, 'confirmed', '2026-06-05', '1000000', $asha);
    $mk($pA, $bA1, 'confirmed', '2026-06-12', '2000000', $asha);
    $mk($pA, $bA2, 'confirmed', '2026-06-10', '500000', $ravi);
    $mk($pB, $bB1, 'confirmed', '2026-06-08', '3000000', $ravi);
    $mk($pA, $bA1, 'confirmed', '2026-05-20', '4000000', $asha);   // previous period
    $mk($pA, $bA1, 'cancelled', '2026-06-06', '9000000', $asha);   // excluded
    $mk($pA, $bA1, 'draft', '2026-06-09', '7000000', $asha);       // excluded

    return compact('asha', 'ravi', 'pA', 'pB', 'bA1', 'bA2', 'bB1');
}

function juneWindow(): ReportFilterData
{
    return new ReportFilterData(
        from: CarbonImmutable::parse('2026-06-01')->startOfDay(),
        to: CarbonImmutable::parse('2026-06-30')->endOfDay(),
        preset: DatePreset::Custom,
    );
}

function salesA(): SalesAnalytics
{
    return app(SalesAnalytics::class);
}

/*
| KPI ACCURACY
*/

it('computes booking count, value and average from confirmed bookings in the window', function () {
    salesWorld();
    $k = salesA()->salesKpis(juneWindow());

    expect($k['bookings'])->toBe(4)                       // cancelled + draft + May excluded
        ->and($k['value'])->toBe(6_500_000.0)
        ->and($k['average'])->toBe(1_625_000.0);         // 6.5M / 4
});

it('returns a null average when there are no bookings', function () {
    $k = salesA()->salesKpis(juneWindow());
    expect($k['bookings'])->toBe(0)->and($k['average'])->toBeNull();
});

it('reports on a chosen booking status when the filter is set (cancelled handling)', function () {
    salesWorld();

    $default = salesA()->salesKpis(juneWindow());
    expect($default['bookings'])->toBe(4);               // cancelled NOT counted by default

    $cancelled = salesA()->salesKpis(new ReportFilterData(
        from: juneWindow()->from, to: juneWindow()->to, preset: DatePreset::Custom, bookingStatus: BookingStatus::Cancelled,
    ));
    expect($cancelled['bookings'])->toBe(1)
        ->and($cancelled['value'])->toBe(9_000_000.0);
});

/*
| FILTERING
*/

it('filters by date window', function () {
    salesWorld();
    $may = salesA()->salesKpis(new ReportFilterData(
        from: CarbonImmutable::parse('2026-05-01')->startOfDay(),
        to: CarbonImmutable::parse('2026-05-31')->endOfDay(),
        preset: DatePreset::Custom,
    ));
    expect($may['bookings'])->toBe(1)->and($may['value'])->toBe(4_000_000.0);
});

it('filters by project', function () {
    $w = salesWorld();
    $k = salesA()->salesKpis(new ReportFilterData(from: juneWindow()->from, to: juneWindow()->to, preset: DatePreset::Custom, projectId: $w['pB']->id));
    expect($k['bookings'])->toBe(1)->and($k['value'])->toBe(3_000_000.0);
});

it('filters by block', function () {
    $w = salesWorld();
    $k = salesA()->salesKpis(new ReportFilterData(from: juneWindow()->from, to: juneWindow()->to, preset: DatePreset::Custom, projectId: $w['pA']->id, blockId: $w['bA1']->id));
    expect($k['bookings'])->toBe(2)->and($k['value'])->toBe(3_000_000.0);
});

it('filters by salesperson', function () {
    $w = salesWorld();
    $k = salesA()->salesKpis(new ReportFilterData(from: juneWindow()->from, to: juneWindow()->to, preset: DatePreset::Custom, salespersonId: $w['asha']->id));
    expect($k['bookings'])->toBe(2)->and($k['value'])->toBe(3_000_000.0);
});

/*
| GROWTH + TREND + VELOCITY
*/

it('computes booking growth vs the previous equivalent period', function () {
    salesWorld();
    $sales = app(SalesReportService::class)->build(ReportFilterData::default(), makeUser(permissions: ['reports.view']));

    // June 4 bookings vs May 1 → +300%
    expect($sales->kpi('total_bookings')->previous)->toBe(1)
        ->and($sales->kpi('total_bookings')->delta())->toBe(300.0)
        ->and($sales->kpi('booking_growth')->value)->toBe(300.0);
});

it('handles a zero previous period without Infinity/NaN', function () {
    salesWorld();
    $early = new ReportFilterData(
        from: CarbonImmutable::parse('2026-02-01')->startOfDay(),
        to: CarbonImmutable::parse('2026-02-28')->endOfDay(),
        preset: DatePreset::Custom,
    );
    $sales = app(SalesReportService::class)->build($early, makeUser(permissions: ['reports.view']));

    expect($sales->kpi('total_bookings')->value)->toBe(0)
        ->and($sales->kpi('total_bookings')->delta())->toBeNull()
        ->and($sales->kpi('booking_growth')->value)->toBeNull();
});

it('aggregates the trend and picks a sensible granularity', function () {
    salesWorld();

    $daily = salesA()->timeSeries(juneWindow(), 'value');
    expect($daily->granularity)->toBe('day')
        ->and($daily->total('value'))->toBe(6_500_000.0)
        ->and($daily->total('bookings'))->toBe(4.0);

    $wide = salesA()->timeSeries(new ReportFilterData(
        from: CarbonImmutable::parse('2026-01-01')->startOfDay(),
        to: CarbonImmutable::parse('2026-12-31')->endOfDay(),
        preset: DatePreset::Custom,
    ), 'value');
    expect($wide->granularity)->toBe('month')
        ->and($wide->total('value'))->toBe(10_500_000.0); // June 6.5M + May 4M
});

it('computes booking velocity over the selected window', function () {
    salesWorld();
    $v = salesA()->bookingVelocity(juneWindow());

    expect($v['days'])->toBe(30)
        ->and($v['bookings'])->toBe(4)
        ->and($v['per_day'])->toBe(round(4 / 30, 2))
        ->and($v['per_week'])->toBe(round(4 / 30 * 7, 2));
});

/*
| TABLES
*/

it('builds project-wise sales with average and absorption', function () {
    $w = salesWorld();
    $rows = salesA()->projectSales(juneWindow());

    $a = collect($rows)->firstWhere('project', 'Green Meadows');
    expect($a['bookings'])->toBe(3)
        ->and($a['value'])->toBe(3_500_000.0)
        ->and($a['average'])->toBe(round(3_500_000 / 3, 2));

    // Green Meadows plots: block A1 = 6 avail + 4 booked + the 3 mk-booked plots; A2 = 3 avail
    $total = Plot::where('project_id', $w['pA']->id)->where('is_active', true)->count();
    $available = Plot::where('project_id', $w['pA']->id)->where('status', 'available')->count();
    expect($a['sold_pct'])->toBe(round(($total - $available) / $total * 100, 1));
});

it('builds block-wise sales with inventory split', function () {
    $w = salesWorld();
    $rows = salesA()->blockSales(juneWindow());

    $a1 = collect($rows)->firstWhere('block', 'Block A1');
    expect($a1['value'])->toBe(3_000_000.0)              // 1M + 2M confirmed in A1 in June
        ->and($a1['available'])->toBe(6)
        ->and($a1['booked'])->toBe(Plot::where('block_id', $w['bA1']->id)->where('status', 'booked')->count())
        ->and($a1['project'])->toBe('Green Meadows');
});

it('builds salesperson performance with bookings and value, sortable', function () {
    salesWorld();

    $byValue = salesA()->salespersonPerformance(juneWindow(), null, 'value');
    expect($byValue[0]['name'])->toBe('Ravi Kumar')      // 3.5M > Asha 3M
        ->and(collect($byValue)->firstWhere('name', 'Asha Rao'))->toMatchArray([
            'bookings' => 2, 'value' => 3_000_000.0,
        ]);
});

it('scopes salesperson performance to one user', function () {
    $w = salesWorld();
    $rows = salesA()->salespersonPerformance(juneWindow(), $w['asha']->id, 'value');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['name'])->toBe('Asha Rao');
});

/*
| SCREEN + SECURITY + DRILL-DOWN
*/

it('renders the sales report with its sections and status badge', function () {
    salesWorld();
    $this->actingAs(makeUser(permissions: ['reports.view', 'projects.view', 'plots.view']))
        ->get(route('reports.sales'))
        ->assertOk()
        ->assertSee('Sales report')
        ->assertSee('Sales trend')
        ->assertSee('Booking velocity')
        ->assertSee('Project-wise sales')
        ->assertSee('Block-wise sales')
        ->assertSee('Salesperson performance')
        ->assertSee('Confirmed bookings');
});

it('forbids the sales report without reports.view', function () {
    $this->actingAs(makeUser(permissions: ['bookings.view']))->get(route('reports.sales'))->assertForbidden();
});

it('rejects foreign filter ids on the sales report', function () {
    $me = makeUser(permissions: ['reports.view', 'projects.view']);
    $this->actingAs($me)->getJson(route('reports.sales', ['project_id' => 999999]))->assertStatus(422)->assertJsonValidationErrors('project_id');

    $pA = Project::factory()->create();
    $foreignBlock = Block::factory()->create(['project_id' => Project::factory()->create()->id]);
    $this->actingAs($me)->getJson(route('reports.sales', ['project_id' => $pA->id, 'block_id' => $foreignBlock->id]))
        ->assertStatus(422)->assertJsonValidationErrors('block_id');

    $this->actingAs($me)
        ->getJson(route('reports.sales', ['salesperson_id' => 999999]))
        ->assertStatus(422)->assertJsonValidationErrors('salesperson_id');
});

it('links sales rows to the project 360 and block plots list', function () {
    $w = salesWorld();
    $html = $this->actingAs(makeUser(permissions: ['reports.view', 'projects.view', 'plots.view']))
        ->get(route('reports.sales'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain(route('projects.show', $w['pA']->id))
        ->toContain(route('plots.index', ['project' => $w['pA']->id, 'block' => $w['bA1']->id]));
});
