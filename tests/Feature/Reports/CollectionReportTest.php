<?php

declare(strict_types=1);

use App\Actions\Collections\RecordChequeBounceAction;
use App\Actions\Payments\CancelPaymentPlanAction;
use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\ReversePaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Enums\AgingBucket;
use App\Enums\BookingStatus;
use App\Enums\DatePreset;
use App\Enums\PaymentStatus;
use App\Enums\PlotStatus;
use App\Models\Block;
use App\Models\Booking;
use App\Models\BookingBuyer;
use App\Models\Buyer;
use App\Models\Plot;
use App\Models\Project;
use App\Models\User;
use App\Services\Payments\PaymentLedger;
use App\Services\Reports\CollectionAnalytics;
use App\Services\Reports\CollectionReportService;
use App\Support\Reports\CollectionFilters;
use App\Support\Reports\ReportFilterData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    $this->travelTo(Carbon::parse('2026-09-15 09:00:00', 'UTC'));
});

// ---------------------------------------------------------------------------
//  world
// ---------------------------------------------------------------------------

/**
 * Three confirmed bookings on live plans:
 *
 *   A  Palm Grove / A1   ₹10 L   sp Asha
 *       i1 ₹4 L due 62d ago   → PAID in full (cash, in window)
 *       i2 ₹3 L due 15d ago   → unpaid, overdue (0–30)
 *       i3 ₹3 L due +5d       → unpaid, upcoming
 *   B  Cedar Heights / B1 ₹20 L  sp Ravi
 *       i1 ₹10 L due 207d ago → ₹2.5 L paid (cash), ₹7.5 L overdue (180+)
 *       i2 ₹10 L due +61d     → unpaid, upcoming
 *   C  Palm Grove / A1   ₹5 L    sp Asha
 *       i1 ₹5 L due 75d ago   → cheque BOUNCED → nothing collected, ₹5 L overdue (61–90)
 *
 * Plus one FAILED cash payment on A (₹1 L) that must be ignored everywhere.
 *
 * @return array<string, mixed>
 */
function colWorld(): array
{
    $actor = User::factory()->create();
    $asha = User::factory()->create(['name' => 'Asha Rao']);
    $ravi = User::factory()->create(['name' => 'Ravi Menon']);

    $palm = Project::factory()->create(['name' => 'Palm Grove']);
    $a1 = Block::factory()->create(['project_id' => $palm->id, 'name' => 'Block A1']);
    $cedar = Project::factory()->create(['name' => 'Cedar Heights']);
    $b1 = Block::factory()->create(['project_id' => $cedar->id, 'name' => 'Block B1']);

    $mk = function (Project $project, Block $block, string $amount, User $sp, string $code): array {
        $plot = Plot::factory()->create([
            'project_id' => $project->id, 'block_id' => $block->id, 'status' => PlotStatus::Booked->value,
        ]);
        $booking = Booking::factory()->confirmed()->forPlot($plot)->create([
            'created_by' => $sp->id,
            'final_amount' => $amount, 'base_amount' => $amount, 'subtotal' => $amount,
        ]);
        $buyer = Buyer::factory()->create(['status' => 'active', 'first_name' => 'Cust', 'last_name' => $code]);
        BookingBuyer::factory()->create([
            'booking_id' => $booking->id, 'buyer_id' => $buyer->id, 'is_primary' => true, 'ownership_percentage' => 100,
        ]);

        return ['booking' => $booking, 'buyer' => $buyer];
    };

    $A = $mk($palm, $a1, '1000000', $asha, 'Aaa');
    $B = $mk($cedar, $b1, '2000000', $ravi, 'Bbb');
    $C = $mk($palm, $a1, '500000', $asha, 'Ccc');

    activePlanFor($A['booking']->fresh(), $actor, [
        ['type' => 'amount', 'value' => '400000', 'due_date' => now()->subDays(62)->toDateString()],
        ['type' => 'amount', 'value' => '300000', 'due_date' => now()->subDays(15)->toDateString()],
        ['type' => 'amount', 'value' => '300000', 'due_date' => now()->addDays(5)->toDateString()],
    ]);
    colPay($A['booking'], $actor, '400000', now()->subDays(10)->toDateString());

    activePlanFor($B['booking']->fresh(), $actor, [
        ['type' => 'amount', 'value' => '1000000', 'due_date' => now()->subDays(207)->toDateString()],
        ['type' => 'amount', 'value' => '1000000', 'due_date' => now()->addDays(61)->toDateString()],
    ]);
    colPay($B['booking'], $actor, '250000', now()->subDays(7)->toDateString());

    activePlanFor($C['booking']->fresh(), $actor, [
        ['type' => 'amount', 'value' => '500000', 'due_date' => now()->subDays(75)->toDateString()],
    ]);
    $cheque = app(RecordPaymentAction::class)->handle([
        'booking_id' => $C['booking']->id,
        'payment_mode_id' => chequeMode()->id,
        'amount' => '500000',
        'cheque_number' => 'CHQ-90210',
        'cheque_date' => now()->subDays(75)->toDateString(),
    ], $actor);
    app(RecordChequeBounceAction::class)->handle($cheque, [
        'bounce_date' => now()->toDateString(),
        'bounce_reason' => 'Insufficient funds',
        'bank_charges' => '750',
    ], collectionManager());

    // A FAILED cash payment — must never be counted.
    $failed = app(RecordPaymentAction::class)->handle([
        'booking_id' => $A['booking']->id, 'payment_mode_id' => cashMode()->id,
        'amount' => '100000', 'payment_date' => now()->subDays(3)->toDateString(),
    ], $actor);
    app(VerifyPaymentAction::class)->handle($failed, PaymentStatus::Failed, $actor);

    return compact('actor', 'asha', 'ravi', 'palm', 'cedar', 'a1', 'b1', 'A', 'B', 'C');
}

function colPay(Booking $booking, User $actor, string $amount, string $date): void
{
    $payment = app(RecordPaymentAction::class)->handle([
        'booking_id' => $booking->id, 'payment_mode_id' => cashMode()->id, 'amount' => $amount, 'payment_date' => $date,
    ], $actor);
    app(VerifyPaymentAction::class)->handle($payment, PaymentStatus::Success, $actor);
}

function colA(): CollectionAnalytics
{
    return app(CollectionAnalytics::class);
}

/** Wide custom window that spans every installment + payment in colWorld(). */
function colFilter(array $o = []): ReportFilterData
{
    return new ReportFilterData(
        from: CarbonImmutable::parse('2026-01-01')->startOfDay(),
        to: CarbonImmutable::parse('2026-12-31')->endOfDay(),
        preset: DatePreset::Custom,
        projectId: $o['projectId'] ?? null,
        blockId: $o['blockId'] ?? null,
        salespersonId: $o['salespersonId'] ?? null,
        paymentStatus: $o['paymentStatus'] ?? null,
    );
}

// ---------------------------------------------------------------------------
//  KPIs
// ---------------------------------------------------------------------------

it('computes the headline KPIs from M7/M8 truth', function () {
    colWorld();
    $k = colA()->kpis(colFilter());

    expect($k['receivable'])->toBe(3_500_000.0)                 // 10 + 20 + 5 L
        ->and($k['collected'])->toBe(650_000.0)                 // 4 L (A) + 2.5 L (B) + 0 (C bounced)
        ->and($k['outstanding'])->toBe(2_850_000.0)             // 6 L + 17.5 L + 5 L
        ->and($k['overdue'])->toBe(1_550_000.0)                 // 3 L + 7.5 L + 5 L
        ->and($k['efficiency'])->toBe(18.6);                    // 650000 / 3500000
});

it('keeps collected + outstanding reconciled to receivable', function () {
    colWorld();
    $k = colA()->kpis(colFilter());

    expect(round($k['collected'] + $k['outstanding'], 2))->toBe($k['receivable']);
});

it('never divides by zero for collection efficiency', function () {
    // A confirmed booking with a live plan but no demand raised is impossible;
    // an empty book must yield null, not NaN / Infinity.
    $k = colA()->kpis(colFilter());

    expect($k['receivable'])->toBe(0.0)
        ->and($k['efficiency'])->toBeNull();
});

it('matches Σ PaymentLedger::installmentOutstanding over the live plans', function () {
    colWorld();

    $ledger = app(PaymentLedger::class);
    $expected = Booking::query()->where('status', BookingStatus::Confirmed->value)->get()
        ->sum(fn (Booking $b) => (float) $ledger->bookingOutstanding($b)->store());

    expect(colA()->kpis(colFilter())['outstanding'])->toBe(round($expected, 2));
});

// ---------------------------------------------------------------------------
//  AGEING — all five buckets
// ---------------------------------------------------------------------------

it('ages overdue receivables into the five M8 buckets, no second calculation', function () {
    colWorld();
    $ageing = collect(colA()->ageing(colFilter()))->keyBy('bucket');

    expect($ageing->keys()->all())->toBe(array_map(fn ($b) => $b->value, AgingBucket::cases()));

    expect($ageing['0-30']['outstanding'])->toBe(300_000.0)
        ->and($ageing['0-30']['installments'])->toBe(1)
        ->and($ageing['0-30']['customers'])->toBe(1);

    expect($ageing['31-60']['outstanding'])->toBe(0.0)            // A/i1 is fully paid → not aged
        ->and($ageing['31-60']['installments'])->toBe(0);

    expect($ageing['61-90']['outstanding'])->toBe(500_000.0)      // C — bounced cheque
        ->and($ageing['61-90']['installments'])->toBe(1)
        ->and($ageing['61-90']['customers'])->toBe(1);

    expect($ageing['91-180']['outstanding'])->toBe(0.0);

    expect($ageing['180+']['outstanding'])->toBe(750_000.0)       // B/i1 partial
        ->and($ageing['180+']['installments'])->toBe(1)
        ->and($ageing['180+']['customers'])->toBe(1);
});

it('exposes the critical (91-180 + 180+) rows most-severe first', function () {
    colWorld();
    $data = app(CollectionReportService::class)
        ->build(colFilter(), CollectionFilters::none(), makeUser(permissions: ['reports.view', 'leads.view_all']));

    $critical = $data->criticalAgeing();
    expect($critical[0]['bucket'])->toBe('180+')
        ->and($critical[1]['bucket'])->toBe('91-180');
});

// ---------------------------------------------------------------------------
//  FILTERS
// ---------------------------------------------------------------------------

it('filters the KPIs by project', function () {
    $w = colWorld();
    $k = colA()->kpis(colFilter(['projectId' => $w['palm']->id]));

    expect($k['receivable'])->toBe(1_500_000.0)      // A + C
        ->and($k['outstanding'])->toBe(1_100_000.0)  // 6 L + 5 L
        ->and($k['overdue'])->toBe(800_000.0);       // 3 L + 5 L
});

it('filters the KPIs by block', function () {
    $w = colWorld();
    $k = colA()->kpis(colFilter(['projectId' => $w['cedar']->id, 'blockId' => $w['b1']->id]));

    expect($k['receivable'])->toBe(2_000_000.0)
        ->and($k['outstanding'])->toBe(1_750_000.0);
});

it('filters the KPIs by salesperson (booking attribution)', function () {
    $w = colWorld();
    $k = colA()->kpis(colFilter(['salespersonId' => $w['ravi']->id]));

    expect($k['receivable'])->toBe(2_000_000.0)      // only booking B
        ->and($k['overdue'])->toBe(750_000.0);
});

it('narrows the recovery report to one ageing bucket', function () {
    colWorld();

    $all = colA()->recoveryReport(colFilter(), CollectionFilters::none());
    expect($all->total())->toBe(3);                  // A/i2, B/i1, C/i1

    $old = colA()->recoveryReport(colFilter(), new CollectionFilters(bucket: AgingBucket::Days180Plus));
    expect($old->total())->toBe(1)
        ->and($old->first()->outstanding)->toBe(750_000.0);

    $mid = colA()->recoveryReport(colFilter(), new CollectionFilters(bucket: AgingBucket::Days61To90));
    expect($mid->total())->toBe(1)
        ->and($mid->first()->days_overdue)->toBe(75);
});

it('narrows the payment-method mix to one method', function () {
    colWorld();
    $cash = cashMode();

    $rows = colA()->paymentMethods(colFilter(), $cash->id);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['method'])->toBe('Cash')
        ->and($rows[0]['amount'])->toBe(650_000.0);
});

// ---------------------------------------------------------------------------
//  FINANCIAL EDGE CASES
// ---------------------------------------------------------------------------

it('counts a fully-paid installment as collected, not outstanding', function () {
    colWorld();
    $rows = colA()->recoveryReport(colFilter(), CollectionFilters::none())->getCollection();

    // A/i1 (₹4 L, paid in full) must not appear in the overdue recovery list.
    expect($rows->pluck('due_amount')->contains(400_000.0))->toBeFalse();
});

it('treats a partial payment as M8 does — outstanding = amount − paid', function () {
    colWorld();
    $row = colA()->recoveryReport(colFilter(), new CollectionFilters(bucket: AgingBucket::Days180Plus))->first();

    expect($row->due_amount)->toBe(1_000_000.0)
        ->and($row->paid)->toBe(250_000.0)
        ->and($row->outstanding)->toBe(750_000.0);
});

it('excludes FAILED and CANCELLED payments from collected money', function () {
    $s = confirmedBookingScenario('1000000');
    activePlanFor($s['booking']->fresh(), $s['actor'], [
        ['type' => 'amount', 'value' => '1000000', 'due_date' => now()->subDays(10)->toDateString()],
    ]);

    $p = app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id,
        'amount' => '400000', 'payment_date' => now()->subDays(2)->toDateString(),
    ], $s['actor']);
    app(VerifyPaymentAction::class)->handle($p, PaymentStatus::Failed, $s['actor']);

    $k = colA()->kpis(colFilter());
    expect($k['collected'])->toBe(0.0)
        ->and($k['outstanding'])->toBe(1_000_000.0);
});

it('does not count a bounced cheque as collected money', function () {
    colWorld();
    $ch = colA()->cheques(colFilter());

    expect($ch['received'])->toBe(1)
        ->and($ch['bounced'])->toBe(1)
        ->and($ch['cleared'])->toBe(0)
        ->and($ch['bounced_amount'])->toBe(500_000.0)
        ->and($ch['bank_charges'])->toBe(750.0);

    // and the ₹5 L is outstanding, never collected
    expect(colA()->kpis(colFilter())['collected'])->toBe(650_000.0);
});

it('restores outstanding when a successful payment is reversed', function () {
    $s = confirmedBookingScenario('1000000');
    activePlanFor($s['booking']->fresh(), $s['actor'], [
        ['type' => 'amount', 'value' => '1000000', 'due_date' => now()->subDays(10)->toDateString()],
    ]);
    $p = app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id,
        'amount' => '600000', 'payment_date' => now()->subDays(2)->toDateString(),
    ], $s['actor']);
    app(VerifyPaymentAction::class)->handle($p, PaymentStatus::Success, $s['actor']);

    expect(colA()->kpis(colFilter())['collected'])->toBe(600_000.0);

    app(ReversePaymentAction::class)->handle($p->fresh(), 'test reversal', financeManager());

    $k = colA()->kpis(colFilter());
    expect($k['collected'])->toBe(0.0)
        ->and($k['outstanding'])->toBe(1_000_000.0);
});

it('ignores installments on non-live (draft / cancelled) plans', function () {
    $s = confirmedBookingScenario('1000000');
    // plan created but never activated → status Draft is live; cancel it instead
    $plan = activePlanFor($s['booking']->fresh(), $s['actor'], [
        ['type' => 'amount', 'value' => '1000000', 'due_date' => now()->subDays(10)->toDateString()],
    ]);
    app(CancelPaymentPlanAction::class)->handle($plan, $s['actor']);

    expect(colA()->kpis(colFilter())['receivable'])->toBe(0.0);
});

// ---------------------------------------------------------------------------
//  AGGREGATIONS
// ---------------------------------------------------------------------------

it('rolls collection up by project, sorted by outstanding', function () {
    colWorld();
    $rows = colA()->projectCollection(colFilter());

    expect($rows[0]['project'])->toBe('Cedar Heights')
        ->and($rows[0]['outstanding'])->toBe(1_750_000.0)
        ->and($rows[1]['project'])->toBe('Palm Grove')
        ->and($rows[1]['receivable'])->toBe(1_500_000.0)
        ->and($rows[1]['collected'])->toBe(400_000.0)
        ->and($rows[1]['overdue'])->toBe(800_000.0)
        ->and($rows[1]['efficiency'])->toBe(26.7);
});

it('rolls collection up by block with the project name', function () {
    colWorld();
    $rows = collect(colA()->blockCollection(colFilter()))->keyBy('block');

    expect($rows['Block A1']['project'])->toBe('Palm Grove')
        ->and($rows['Block A1']['outstanding'])->toBe(1_100_000.0)
        ->and($rows['Block B1']['outstanding'])->toBe(1_750_000.0);
});

it('rolls collection up by salesperson using booking attribution', function () {
    $w = colWorld();
    $rows = collect(colA()->salespersonCollection(colFilter()))->keyBy('name');

    expect($rows['Asha Rao']['customers'])->toBe(2)
        ->and($rows['Asha Rao']['receivable'])->toBe(1_500_000.0)
        ->and($rows['Asha Rao']['overdue'])->toBe(800_000.0)
        ->and($rows['Ravi Menon']['receivable'])->toBe(2_000_000.0);
});

it('scopes salesperson collection to one id', function () {
    $w = colWorld();
    $rows = colA()->salespersonCollection(colFilter(), $w['asha']->id);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['name'])->toBe('Asha Rao');
});

it('separates total outstanding from overdue in the top-customer lists', function () {
    colWorld();
    $top = colA()->topCustomers(colFilter());

    expect($top['outstanding'][0]['amount'])->toBe(1_750_000.0)     // customer B
        ->and(collect($top['outstanding'])->pluck('amount')->all())->toBe([1_750_000.0, 600_000.0, 500_000.0])
        ->and(collect($top['overdue'])->pluck('amount')->all())->toBe([750_000.0, 500_000.0, 300_000.0]);
});

it('builds the monthly collection table without SQLite date functions', function () {
    colWorld();
    $rows = collect(colA()->monthly(colFilter()))->keyBy('month');

    expect($rows['Sep 2026']['collected'])->toBe(650_000.0)
        ->and($rows['Sep 2026']['receivable'])->toBe(300_000.0)     // A/i3 due this month
        ->and($rows['Jul 2026']['receivable'])->toBe(900_000.0);    // A/i1 ₹4 L + C ₹5 L
});

it('reports expected collection for the next 7 and 30 days', function () {
    colWorld();
    $exp = colA()->expected(colFilter());

    expect($exp['next7'])->toBe(300_000.0)     // A/i3 due +5d
        ->and($exp['next30'])->toBe(300_000.0);
});

it('distinguishes booking value from cash collection in the reconciliation', function () {
    colWorld();
    $r = colA()->reconciliation(colFilter());

    expect($r['bookingValue'])->toBe(3_500_000.0)
        ->and($r['receivable'])->toBe(3_500_000.0)
        ->and($r['cashCollected'])->toBe(650_000.0)
        ->and($r['outstanding'])->toBe(2_850_000.0)
        ->and($r['unallocated'])->toBe(0.0);
});

it('builds a bounded number of queries (no N+1)', function () {
    colWorld();
    $user = makeUser(permissions: ['reports.view', 'leads.view_all']);

    DB::enableQueryLog();
    app(CollectionReportService::class)->build(colFilter(), CollectionFilters::none(), $user);
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($count)->toBeLessThan(40);
});

// ---------------------------------------------------------------------------
//  SCREEN + SECURITY + DRILL-DOWN
// ---------------------------------------------------------------------------

it('renders the collections report with every section', function () {
    colWorld();
    $this->actingAs(makeUser(permissions: ['reports.view', 'leads.view_all', 'projects.view', 'plots.view', 'buyers.view', 'bookings.view', 'payments.view', 'collections.view']))
        ->get(route('reports.collections'))
        ->assertOk()
        ->assertSee('Collections report')
        ->assertSee('Total receivable')
        ->assertSee('Collection efficiency')
        ->assertSee('Ageing of overdue receivables')
        ->assertSee('Critical overdue')
        ->assertSee('Project collection')
        ->assertSee('Block collection')
        ->assertSee('Salesperson collection')
        ->assertSee('Customer recovery report')
        ->assertSee('Top outstanding customers')
        ->assertSee('Payment method mix')
        ->assertSee('Cheque analytics')
        ->assertSee('Monthly collection')
        ->assertSee('Expected collection')
        ->assertSee('Booking value vs collection');
});

it('forbids the collections report without reports.view', function () {
    $this->actingAs(makeUser(permissions: ['collections.view']))
        ->get(route('reports.collections'))
        ->assertForbidden();
});

it('rejects a foreign project id on the collections report', function () {
    $me = makeUser(permissions: ['reports.view']);

    $this->actingAs($me)->getJson(route('reports.collections', ['project_id' => 999999]))
        ->assertStatus(422)->assertJsonValidationErrors('project_id');
});

it('rejects a foreign block id on the collections report', function () {
    $me = makeUser(permissions: ['reports.view']);
    $palm = Project::factory()->create();
    $foreignBlock = Block::factory()->create(['project_id' => Project::factory()->create()->id]);

    $this->actingAs($me)->getJson(route('reports.collections', ['project_id' => $palm->id, 'block_id' => $foreignBlock->id]))
        ->assertStatus(422)->assertJsonValidationErrors('block_id');
});

it('rejects an unknown ageing bucket and payment method', function () {
    $me = makeUser(permissions: ['reports.view']);

    $this->actingAs($me)->getJson(route('reports.collections', ['ageing_bucket' => 'nope']))
        ->assertStatus(422)->assertJsonValidationErrors('ageing_bucket');

    $this->actingAs($me)->getJson(route('reports.collections', ['payment_method' => 999999]))
        ->assertStatus(422)->assertJsonValidationErrors('payment_method');
});

it('scopes salesperson collection for a user without leads.view_all', function () {
    $w = colWorld();

    // Asha can see reports but not everyone's — only her own attribution.
    $asha = $w['asha'];
    $asha->givePermissionTo('reports.view');

    $html = $this->actingAs($asha)->get(route('reports.collections'))->assertOk()->getContent();

    expect($html)->toContain('Asha Rao')
        ->not->toContain('Ravi Menon');
});

it('links recovery + roll-up rows to existing detail pages', function () {
    $w = colWorld();
    $html = $this->actingAs(makeUser(permissions: ['reports.view', 'leads.view_all', 'projects.view', 'plots.view', 'buyers.view', 'bookings.view', 'payments.view']))
        ->get(route('reports.collections'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain(route('projects.show', $w['palm']->id))
        ->toContain(route('plots.index', ['project' => $w['palm']->id, 'block' => $w['a1']->id]))
        ->toContain(route('buyers.show', $w['B']['buyer']->id))
        ->toContain(route('bookings.show', $w['B']['booking']->id))
        ->toContain(route('payments.booking', $w['B']['booking']->id));
});

it('keeps the ReportScreenTest contract — title + filters visible', function () {
    $this->actingAs(makeUser(permissions: ['reports.view', 'leads.view_all']))
        ->get(route('reports.collections'))
        ->assertOk()
        ->assertSee('Collections report')
        ->assertSee('Filters');
});
