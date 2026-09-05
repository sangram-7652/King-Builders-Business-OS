<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Payments\PaymentLedger;
use App\Services\Reports\MisAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    $this->travelTo(Carbon::parse('2026-09-15 09:00:00', 'UTC'));
});

// misWorld(), misA(), misFilter() are shared Pest helpers (tests/Pest.php).

// ---------------------------------------------------------------------------
//  KPIs
// ---------------------------------------------------------------------------

it('computes the management KPIs from the existing analytics — M7/M8 truth', function () {
    misWorld();
    $k = misA()->kpis(misFilter());

    expect($k['total_projects'])->toBe(2)
        ->and($k['total_plots'])->toBe(10)
        ->and($k['available'])->toBe(7)
        ->and($k['booked'])->toBe(3)
        ->and($k['total_bookings'])->toBe(2)
        ->and($k['booking_value'])->toBe(3_000_000.0)
        ->and($k['receivable'])->toBe(3_000_000.0)
        ->and($k['collected'])->toBe(300_000.0)
        ->and($k['outstanding'])->toBe(2_700_000.0)  // never booking value − collected
        ->and($k['overdue'])->toBe(1_000_000.0)      // B/i1 800k + A/i1 unpaid 200k
        ->and($k['collection_efficiency'])->toBe(10.0)
        ->and($k['total_leads'])->toBe(5)
        ->and($k['converted_leads'])->toBe(1)
        ->and($k['conversion_pct'])->toBe(20.0);
});

it('matches Σ PaymentLedger outstanding over confirmed bookings', function () {
    misWorld();
    $ledger = app(PaymentLedger::class);
    $expected = Booking::query()->where('status', BookingStatus::Confirmed->value)->get()
        ->sum(fn (Booking $b) => (float) $ledger->bookingOutstanding($b)->store());

    expect(misA()->kpis(misFilter())['outstanding'])->toBe(round($expected, 2));
});

// ---------------------------------------------------------------------------
//  DAILY / MONTHLY AGGREGATION
// ---------------------------------------------------------------------------

it('builds one daily row per day with SQL-aggregated figures', function () {
    misWorld();
    $daily = misA()->daily(misFilter());

    expect($daily['truncated'])->toBeFalse()
        ->and($daily['rows'])->toHaveCount(30);

    $byDate = collect($daily['rows'])->keyBy('date');
    expect($byDate['2026-09-05']['bookings'])->toBe(1)
        ->and($byDate['2026-09-05']['booking_value'])->toBe(1_000_000.0)
        ->and($byDate['2026-09-08']['booking_value'])->toBe(2_000_000.0)
        ->and($byDate['2026-09-10']['receivable'])->toBe(500_000.0)
        ->and($byDate['2026-09-10']['outstanding'])->toBe(200_000.0)
        ->and($byDate['2026-09-10']['overdue'])->toBe(200_000.0)
        ->and($byDate['2026-09-12']['collected'])->toBe(300_000.0)
        ->and($byDate['2026-09-03']['leads'])->toBe(3)   // Asha's 3
        ->and($byDate['2026-09-04']['leads'])->toBe(2);  // Ravi's 2
});

it('caps the daily table and flags truncation on a very wide window', function () {
    misWorld();
    $daily = misA()->daily(misFilter(['from' => '2024-01-01', 'to' => '2026-09-30']));

    expect($daily['truncated'])->toBeTrue()
        ->and(count($daily['rows']))->toBe(MisAnalytics::MAX_DAILY_ROWS)
        ->and($daily['rows'][array_key_last($daily['rows'])]['date'])->toBe('2026-09-30');
});

it('builds monthly rows with consistent (non-negative) period semantics', function () {
    misWorld();
    $rows = collect(misA()->monthly(misFilter()))->keyBy('month');

    expect($rows)->toHaveCount(1)
        ->and($rows['Sep 2026']['bookings'])->toBe(2)
        ->and($rows['Sep 2026']['booking_value'])->toBe(3_000_000.0)
        ->and($rows['Sep 2026']['receivable'])->toBe(500_000.0)   // only A/i1 due in Sept
        ->and($rows['Sep 2026']['collected'])->toBe(300_000.0)
        ->and($rows['Sep 2026']['outstanding'])->toBe(200_000.0)
        ->and($rows['Sep 2026']['collection_efficiency'])->toBe(60.0)
        ->and($rows['Sep 2026']['leads'])->toBe(5)
        ->and($rows['Sep 2026']['converted_leads'])->toBe(1);
});

// ---------------------------------------------------------------------------
//  PROJECT / SALESPERSON / COLLECTION AGGREGATION
// ---------------------------------------------------------------------------

it('merges inventory, sales and M8 collection into the project MIS', function () {
    misWorld();
    $rows = collect(misA()->projects(misFilter()))->keyBy('project');

    expect($rows['Alpha Estate']['plots'])->toBe(6)
        ->and($rows['Alpha Estate']['booked'])->toBe(2)
        ->and($rows['Alpha Estate']['available'])->toBe(4)
        ->and($rows['Alpha Estate']['sales_value'])->toBe(1_000_000.0)
        ->and($rows['Alpha Estate']['collected'])->toBe(300_000.0)
        ->and($rows['Alpha Estate']['outstanding'])->toBe(700_000.0)
        ->and($rows['Alpha Estate']['overdue'])->toBe(200_000.0)
        ->and($rows['Beta Park']['outstanding'])->toBe(2_000_000.0);
});

it('merges sales performance and M8 collection into the salesperson MIS', function () {
    misWorld();
    $rows = collect(misA()->salespeople(misFilter()))->keyBy('name');

    expect($rows['Asha Rao']['leads'])->toBe(3)
        ->and($rows['Asha Rao']['bookings'])->toBe(1)
        ->and($rows['Asha Rao']['booking_value'])->toBe(1_000_000.0)
        ->and($rows['Asha Rao']['collected'])->toBe(300_000.0)
        ->and($rows['Asha Rao']['outstanding'])->toBe(700_000.0)
        ->and($rows['Asha Rao']['conversion'])->toBe(33.3)
        ->and($rows['Ravi Menon']['booking_value'])->toBe(2_000_000.0)
        ->and($rows['Ravi Menon']['outstanding'])->toBe(2_000_000.0);
});

it('scopes the salesperson MIS to one id', function () {
    $w = misWorld();
    $rows = misA()->salespeople(misFilter(), $w['ravi']->id);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['name'])->toBe('Ravi Menon');
});

it('reports the collection MIS from M8 truth', function () {
    misWorld();
    $c = misA()->collectionSummary(misFilter());

    expect($c['booking_value'])->toBe(3_000_000.0)
        ->and($c['receivable'])->toBe(3_000_000.0)
        ->and($c['collected'])->toBe(300_000.0)
        ->and($c['cash_collected'])->toBe(300_000.0)
        ->and($c['outstanding'])->toBe(2_700_000.0)
        ->and($c['collection_efficiency'])->toBe(10.0);
});

// ---------------------------------------------------------------------------
//  FILTERS
// ---------------------------------------------------------------------------

it('filters the MIS by project', function () {
    $w = misWorld();
    $k = misA()->kpis(misFilter(['projectId' => $w['beta']->id]));

    expect($k['total_bookings'])->toBe(1)
        ->and($k['booking_value'])->toBe(2_000_000.0)
        ->and($k['outstanding'])->toBe(2_000_000.0)
        ->and($k['overdue'])->toBe(800_000.0);
});

it('filters the MIS by salesperson', function () {
    $w = misWorld();
    $k = misA()->kpis(misFilter(['salespersonId' => $w['asha']->id]));

    expect($k['booking_value'])->toBe(1_000_000.0)
        ->and($k['outstanding'])->toBe(700_000.0);
});

// ---------------------------------------------------------------------------
//  SCREEN + SECURITY
// ---------------------------------------------------------------------------

it('renders the MIS report with all sections', function () {
    misWorld();
    $this->actingAs(makeUser(permissions: ['reports.view', 'leads.view_all', 'projects.view']))
        ->get(route('reports.mis'))
        ->assertOk()
        ->assertSee('MIS report')
        ->assertSee('Total projects')
        ->assertSee('Collection MIS')
        ->assertSee('Project MIS')
        ->assertSee('Salesperson MIS')
        ->assertSee('Monthly MIS')
        ->assertSee('Daily MIS')
        ->assertSee('This month');
});

it('forbids the MIS report without reports.view', function () {
    $this->actingAs(makeUser(permissions: ['bookings.view']))
        ->get(route('reports.mis'))
        ->assertForbidden();
});

it('rejects a foreign project id on the MIS report', function () {
    $this->actingAs(makeUser(permissions: ['reports.view']))
        ->getJson(route('reports.mis', ['project_id' => 999999]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('project_id');
});

it('scopes the MIS salesperson table for a user without leads.view_all', function () {
    $w = misWorld();
    $w['asha']->givePermissionTo('reports.view');

    $this->actingAs($w['asha'])
        ->get(route('reports.mis'))
        ->assertOk()
        ->assertSee('Asha Rao')
        ->assertDontSee('Ravi Menon');
});

it('renders an honest empty MIS when there is no data', function () {
    $this->actingAs(makeUser(permissions: ['reports.view']))
        ->get(route('reports.mis'))
        ->assertOk()
        ->assertDontSee('Unavailable');
});
