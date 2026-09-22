<?php

declare(strict_types=1);

use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Enums\BookingStatus;
use App\Enums\DatePreset;
use App\Enums\PaymentStatus;
use App\Enums\PlotStatus;
use App\Enums\TransferRequestStatus;
use App\Models\Block;
use App\Models\Booking;
use App\Models\BookingBuyer;
use App\Models\Buyer;
use App\Models\Document;
use App\Models\Plot;
use App\Models\Project;
use App\Models\TransferRequest;
use App\Models\User;
use App\Services\Reports\ExecutiveDashboardService;
use App\Services\Reports\InventoryAnalytics;
use App\Services\Reports\PaymentsAnalytics;
use App\Services\Reports\SalesAnalytics;
use App\Support\Reports\ExecutiveDashboardData;
use App\Support\Reports\ReportFilterData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    $this->travelTo(Carbon::parse('2026-06-15 09:00:00', 'UTC'));
});

/**
 * A known dashboard world. June 2026 is "this month".
 *
 * @return array<string, mixed>
 */
function execWorld(): array
{
    $asha = User::factory()->create(['name' => 'Asha Rao']);
    $ravi = User::factory()->create(['name' => 'Ravi Kumar']);
    $actor = User::factory()->create();

    // --- Projects + plots (snapshot) ----------------------------------
    $projectA = Project::factory()->create(['name' => 'Green Meadows']);
    $blockA = Block::factory()->create(['project_id' => $projectA->id]);
    Plot::factory()->count(5)->create(['project_id' => $projectA->id, 'block_id' => $blockA->id, 'status' => PlotStatus::Available->value]);
    Plot::factory()->count(3)->create(['project_id' => $projectA->id, 'block_id' => $blockA->id, 'status' => PlotStatus::Booked->value]);
    Plot::factory()->create(['project_id' => $projectA->id, 'block_id' => $blockA->id, 'status' => PlotStatus::Sold->value]);

    $projectB = Project::factory()->create(['name' => 'Blue Ridge']);
    $blockB = Block::factory()->create(['project_id' => $projectB->id]);
    Plot::factory()->count(2)->create(['project_id' => $projectB->id, 'block_id' => $blockB->id, 'status' => PlotStatus::Available->value]);
    Plot::factory()->create(['project_id' => $projectB->id, 'block_id' => $blockB->id, 'status' => PlotStatus::Booked->value]);

    $mkBooking = function (Project $project, Block $block, string $status, string $date, string $amount, User $sp) {
        $plot = Plot::factory()->create(['project_id' => $project->id, 'block_id' => $block->id, 'status' => PlotStatus::Booked->value]);
        $booking = Booking::factory()->status(BookingStatus::from($status))->create([
            'project_id' => $project->id, 'block_id' => $block->id, 'plot_id' => $plot->id,
            'created_by' => $sp->id, 'booking_date' => $date,
            'final_amount' => $amount, 'base_amount' => $amount, 'subtotal' => $amount,
            'confirmed_at' => $status === 'confirmed' ? $date : null,
        ]);
        $buyer = Buyer::factory()->create(['status' => 'active', 'first_name' => 'Buyer', 'last_name' => $booking->booking_number]);
        BookingBuyer::factory()->create(['booking_id' => $booking->id, 'buyer_id' => $buyer->id, 'is_primary' => true, 'ownership_percentage' => 100]);

        return $booking;
    };

    // --- Bookings ---------------------------------------------------
    $a1 = $mkBooking($projectA, $blockA, 'confirmed', '2026-06-05', '1000000', $asha);
    $a2 = $mkBooking($projectA, $blockA, 'confirmed', '2026-06-10', '2000000', $asha);
    $b1 = $mkBooking($projectB, $blockB, 'confirmed', '2026-06-12', '500000', $ravi);
    $may = $mkBooking($projectA, $blockA, 'confirmed', '2026-05-20', '3000000', $ravi); // previous period
    $cancelled = $mkBooking($projectA, $blockA, 'cancelled', '2026-06-08', '9000000', $asha);
    $draft = $mkBooking($projectA, $blockA, 'draft', '2026-06-09', '4000000', $asha);

    // --- Payments -------------------------------------------------
    // a1: partial 400,000 collected in June (₹600,000 still outstanding)
    execPay($a1->fresh(), $actor, '400000', '2026-06-06');

    // a2: unpaid (₹2,000,000 outstanding)

    // b1: fully paid in June
    execPay($b1->fresh(), $actor, '500000', '2026-06-13');

    // --- Operational (M9/M10) -----------------------------------
    // Registry and Possession are now simple booking-level statuses
    // (RegistryStatus / PossessionStatus) that default to Pending —
    // a1/a2/b1 all start Pending with no extra setup.
    TransferRequest::factory()->forBooking($a1)->status(TransferRequestStatus::UnderReview)->create();
    Document::factory()->uploaded()->forDocumentable($a1)->create();

    return compact('asha', 'ravi', 'actor', 'projectA', 'projectB', 'a1', 'a2', 'b1', 'may', 'cancelled', 'draft');
}

function execPay(Booking $booking, User $actor, string $amount, string $date): void
{
    $payment = app(RecordPaymentAction::class)->handle([
        'booking_id' => $booking->id, 'payment_mode_id' => cashMode()->id, 'amount' => $amount, 'payment_date' => $date,
    ], $actor);
    app(VerifyPaymentAction::class)->handle($payment, PaymentStatus::Success, $actor);
}

function execDashboard(ReportFilterData $filters, ?User $user = null): ExecutiveDashboardData
{
    return app(ExecutiveDashboardService::class)->build($filters, $user ?? makeUser(permissions: ['reports.view']));
}

/*
| KPI ACCURACY
*/

it('computes the sales KPIs from confirmed bookings in the period only', function () {
    execWorld();
    $d = execDashboard(ReportFilterData::default());

    // cancelled (9M), draft (4M) and the May booking (3M) are all excluded
    expect($d->kpi('total_bookings')->value)->toBe(3)
        ->and($d->kpi('booking_value')->value)->toBe(3_500_000.0);
});

it('counts multiple bookings for the same project / salesperson', function () {
    execWorld();
    $d = execDashboard(ReportFilterData::default());

    // Asha has a1 + a2 both in June
    $top = collect($d->topSalespeople)->firstWhere('name', 'Asha Rao');
    expect($top['bookings'])->toBe(2)
        ->and($top['value'])->toBe(3_000_000.0);
});

it('computes collected as successful payments in the period', function () {
    execWorld();
    $d = execDashboard(ReportFilterData::default());

    // 400,000 (a1, 06-06) + 500,000 (b1, 06-13)
    expect($d->kpi('total_collected')->value)->toBe(900_000.0);
});

it('computes outstanding as booking value minus collected, across the whole book (M11.6)', function () {
    execWorld();
    $d = execDashboard(ReportFilterData::default());

    // Single source of M7 truth (PaymentsAnalytics); every M11 surface must agree.
    $m7 = app(PaymentsAnalytics::class)->kpis(ReportFilterData::default())['outstanding'];

    expect($d->kpi('outstanding')->value)->toBe($m7)
        // a1 ₹0.6M + a2 ₹2.0M + b1 ₹0 + may ₹3.0M (unpaid, no date filter on this
        // snapshot KPI) = ₹5.6M. There is no installment schedule anymore —
        // outstanding is simply booking value minus successful payments.
        ->and($d->kpi('outstanding')->value)->toBe(5_600_000.0)
        ->and($d->kpi('outstanding')->hasComparison())->toBeFalse(); // snapshot, no delta
});

it('counts a partial payment as partly collected, not fully', function () {
    execWorld();
    $collection = app(PaymentsAnalytics::class)->summary(ReportFilterData::default());

    // a1 owes 1,000,000, paid 400,000 → still 600,000 outstanding on that booking
    expect($collection['collectedAllTime'])->toBe(900_000.0)
        ->and($collection['bookingValue'])->toBe(6_500_000.0);
});

it('derives collection % safely and never returns Infinity/NaN', function () {
    // no bookings at all
    $d = execDashboard(ReportFilterData::default());
    expect($d->kpi('collection_percent')->value)->toBeNull();
    expect($d->kpi('collection_percent')->delta())->toBeNull();

    execWorld();
    $d = execDashboard(ReportFilterData::default());
    expect($d->kpi('collection_percent')->value)->toBe(13.8); // 900k / 6.5M
});

it('computes the inventory + project + operational snapshot KPIs', function () {
    execWorld();
    $d = execDashboard(ReportFilterData::default());

    expect($d->kpi('total_projects')->value)->toBe(2)
        ->and($d->kpi('total_plots')->value)->toBe(Plot::where('is_active', true)->count())
        ->and($d->kpi('available_plots')->value)->toBe(Plot::where('status', 'available')->count())
        ->and($d->kpi('booked_plots')->value)->toBe(Plot::where('status', 'booked')->count())
        // Registry and Possession both default to Pending for every confirmed
        // booking now, and (like transfer) this KPI is not date-windowed —
        // all 4 confirmed bookings count: a1, a2, b1, may.
        ->and($d->kpi('registry_pending')->value)->toBe(4)
        ->and($d->kpi('possession_pending')->value)->toBe(4)
        ->and($d->kpi('transfer_pending')->value)->toBe(1);
});

/*
| PERIOD COMPARISON
*/

it('compares the current period with the previous equivalent period', function () {
    execWorld();
    $d = execDashboard(ReportFilterData::default());

    // June: 3 bookings / 3.5M. May (previous): 1 booking / 3M.
    $bookings = $d->kpi('total_bookings');
    expect($bookings->previous)->toBe(1)
        ->and($bookings->delta())->toBe(200.0)      // (3-1)/1 * 100
        ->and($bookings->direction())->toBe('up');

    $value = $d->kpi('booking_value');
    expect($value->previous)->toBe(3_000_000.0)
        ->and($value->delta())->toBe(round((3_500_000 - 3_000_000) / 3_000_000 * 100, 1));
});

it('handles a zero previous period without dividing by zero', function () {
    execWorld();
    // window with nothing before it
    $filters = new ReportFilterData(
        from: CarbonImmutable::parse('2026-06-01')->startOfDay(),
        to: CarbonImmutable::parse('2026-06-30')->endOfDay(),
        preset: DatePreset::Custom,
    );
    $d = execDashboard($filters);

    // previous window = May 2026 which also has the 3M booking; use an earlier window instead
    $early = new ReportFilterData(
        from: CarbonImmutable::parse('2026-03-01')->startOfDay(),
        to: CarbonImmutable::parse('2026-03-31')->endOfDay(),
        preset: DatePreset::Custom,
    );
    $d = execDashboard($early);

    $bookings = $d->kpi('total_bookings');
    expect($bookings->value)->toBe(0)
        ->and($bookings->previous)->toBe(0)
        ->and($bookings->delta())->toBeNull()          // no division by zero
        ->and($bookings->direction())->toBeNull();
});

/*
| FILTERING
*/

it('filters KPIs by date window', function () {
    execWorld();
    $d = execDashboard(new ReportFilterData(
        from: CarbonImmutable::parse('2026-05-01')->startOfDay(),
        to: CarbonImmutable::parse('2026-05-31')->endOfDay(),
        preset: DatePreset::Custom,
    ));

    expect($d->kpi('total_bookings')->value)->toBe(1)             // only the May booking
        ->and($d->kpi('booking_value')->value)->toBe(3_000_000.0);
});

it('filters KPIs by project', function () {
    $w = execWorld();
    $d = execDashboard(new ReportFilterData(
        from: CarbonImmutable::parse('2026-06-01')->startOfDay(),
        to: CarbonImmutable::parse('2026-06-30')->endOfDay(),
        preset: DatePreset::Custom,
        projectId: $w['projectB']->id,
    ));

    expect($d->kpi('total_bookings')->value)->toBe(1)              // only b1
        ->and($d->kpi('booking_value')->value)->toBe(500_000.0)
        ->and($d->kpi('total_projects')->value)->toBe(1);
});

it('filters KPIs by salesperson', function () {
    $w = execWorld();
    $d = execDashboard(new ReportFilterData(
        from: CarbonImmutable::parse('2026-06-01')->startOfDay(),
        to: CarbonImmutable::parse('2026-06-30')->endOfDay(),
        preset: DatePreset::Custom,
        salespersonId: $w['asha']->id,
    ));

    expect($d->kpi('total_bookings')->value)->toBe(2)              // a1 + a2
        ->and($d->kpi('booking_value')->value)->toBe(3_000_000.0);
});

/*
| AGGREGATIONS
*/

it('aggregates the inventory status distribution from the M4 enum', function () {
    execWorld();
    $inv = app(InventoryAnalytics::class)->statusDistribution(ReportFilterData::default());

    // every PlotStatus present, zero-filled, no invented keys
    expect(array_keys($inv))->toBe(array_map(fn ($c) => $c->value, PlotStatus::cases()))
        ->and($inv['available'])->toBe(Plot::where('status', 'available')->count())
        ->and($inv['booked'])->toBe(Plot::where('status', 'booked')->count())
        ->and(array_sum($inv))->toBe(Plot::where('is_active', true)->count());
});

it('aggregates the booking status distribution from the M6 enum', function () {
    execWorld();
    $dist = app(SalesAnalytics::class)->statusDistribution(new ReportFilterData(
        from: CarbonImmutable::parse('2026-06-01')->startOfDay(),
        to: CarbonImmutable::parse('2026-06-30')->endOfDay(),
        preset: DatePreset::Custom,
    ));

    expect(array_keys($dist))->toBe(array_map(fn ($c) => $c->value, BookingStatus::cases()))
        ->and($dist['confirmed'])->toBe(3)
        ->and($dist['cancelled'])->toBe(1)
        ->and($dist['draft'])->toBe(1);
});

/*
| SECTIONS
*/

it('lists recent bookings newest-first, excluding drafts, with related data', function () {
    execWorld();
    $recent = app(SalesAnalytics::class)->recentBookings(new ReportFilterData(
        from: CarbonImmutable::parse('2026-06-01')->startOfDay(),
        to: CarbonImmutable::parse('2026-06-30')->endOfDay(),
        preset: DatePreset::Custom,
    ));

    $numbers = array_column($recent, 'status');
    expect($numbers)->not->toContain('draft')
        ->and($recent[0]['date'])->toBe('2026-06-12')    // b1 is the latest confirmed
        ->and($recent[0]['project'])->toBe('Blue Ridge')
        ->and($recent[0]['customer'])->not->toBe('—');
});

it('builds project performance with no N+1 (bounded query count)', function () {
    execWorld();

    DB::enableQueryLog();
    $rows = app(SalesAnalytics::class)->projectPerformance(ReportFilterData::default());
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($count)->toBeLessThanOrEqual(6)               // 5 aggregates + a little slack, never per-project
        ->and(collect($rows)->firstWhere('project', 'Green Meadows')['booked'])->toBe(2);
});

it('counts operational alerts from real M9/M10 rows only', function () {
    execWorld();
    $d = execDashboard(ReportFilterData::default());

    $alerts = collect($d->attention)->keyBy('key');
    // Registry and Possession both default to Pending for every confirmed
    // booking now (a1, a2, b1, may)
    expect($alerts['registry']['count'])->toBe(4)
        ->and($alerts['possession']['count'])->toBe(4)
        ->and($alerts['transfers']['count'])->toBe(1)     // the under_review transfer
        ->and($alerts['documents']['count'])->toBe(1);
});

it('does not count a completed transfer as pending (transferred ownership edge case)', function () {
    $w = execWorld();
    TransferRequest::factory()->forBooking($w['b1'])->status(TransferRequestStatus::Completed)->create();

    $d = execDashboard(ReportFilterData::default());

    // still just the one under_review transfer, and b1 still contributes its value once
    expect($d->kpi('transfer_pending')->value)->toBe(1)
        ->and($d->kpi('booking_value')->value)->toBe(3_500_000.0);
});

/*
| ERROR ISOLATION
*/

it('reports a section error instead of a fake zero when a query fails', function () {
    execWorld();

    // Registry and Possession are now plain `bookings.*_status` columns (no
    // longer read `registry_cases` / `possession_cases`), so break
    // `transfer_requests` instead to prove the SAME error-isolation
    // behaviour for OperationsAnalytics::transferPending().
    Schema::drop('transfer_requests');

    $d = execDashboard(ReportFilterData::default());

    expect($d->kpi('transfer_pending')->value)->toBeNull()
        ->and($d->kpi('transfer_pending')->failed())->toBeTrue()
        ->and($d->hasErrors())->toBeTrue()
        // the rest of the dashboard still works
        ->and($d->kpi('total_bookings')->value)->toBe(3);
});
