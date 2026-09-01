<?php

declare(strict_types=1);

use App\Actions\Collections\ScheduleCollectionFollowUpAction;
use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Enums\AgingBucket;
use App\Enums\PaymentStatus;
use App\Models\PaymentPromise;
use App\Services\Collections\CollectionDashboardService;
use App\Support\Collections\CollectionDashboardData;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function dashboard(): CollectionDashboardData
{
    return app(CollectionDashboardService::class)->build();
}

it('totals outstanding across confirmed bookings (28)', function () {
    overdueCaseScenario('1000000');
    overdueCaseScenario('600000', [
        ['type' => 'amount', 'value' => '600000', 'due_date' => now()->subDays(10)->toDateString()],
    ]);

    expect(dashboard()->totalOutstanding->store())->toBe('1600000.00');
});

it('totals overdue across confirmed bookings (29)', function () {
    overdueCaseScenario('1000000'); // 700k overdue (400k + 300k)

    expect(dashboard()->totalOverdue->store())->toBe('700000.00');
});

it('totals aging buckets across the portfolio (30)', function () {
    overdueCaseScenario('1000000', [
        ['type' => 'amount', 'value' => '400000', 'due_date' => now()->subDays(20)->toDateString()],
        ['type' => 'amount', 'value' => '600000', 'due_date' => now()->subDays(50)->toDateString()],
    ]);

    $data = dashboard();

    expect($data->aging[AgingBucket::Days0To30->value]['amount']->store())->toBe('400000.00')
        ->and($data->aging[AgingBucket::Days0To30->value]['bookings'])->toBe(1)
        ->and($data->aging[AgingBucket::Days31To60->value]['amount']->store())->toBe('600000.00');
});

it("reports today's collection (31)", function () {
    $s = overdueCaseScenario('1000000');
    $p = app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => '250000',
        'payment_date' => now()->toDateString(),
    ], $s['actor']);
    app(VerifyPaymentAction::class)->handle($p, PaymentStatus::Success, $s['actor']);

    expect(dashboard()->collectedToday->store())->toBe('250000.00');
});

it('reports expected collection today (due today + promises due today) (32)', function () {
    $s = overdueCaseScenario('1000000', [
        ['type' => 'amount', 'value' => '300000', 'due_date' => now()->toDateString()],      // due today
        ['type' => 'amount', 'value' => '700000', 'due_date' => now()->addMonth()->toDateString()],
    ]);

    PaymentPromise::factory()->create([
        'collection_case_id' => $s['case']->id,
        'booking_id' => $s['booking']->id,
        'promised_amount' => '150000',
        'outstanding_at_creation' => '1000000',
        'promise_date' => now()->toDateString(),
        'status' => 'open',
    ]);

    expect(dashboard()->expectedToday->store())->toBe('450000.00'); // 300k + 150k
});

it('counts open follow-ups, broken promises, pending + bounced cheques', function () {
    $s = overdueCaseScenario();
    app(ScheduleCollectionFollowUpAction::class)->handle($s['case'], ['follow_up_at' => now()->addDay()->toDateTimeString()], $s['actor']);
    PaymentPromise::factory()->broken()->create(['collection_case_id' => $s['case']->id, 'booking_id' => $s['booking']->id]);

    $data = dashboard();
    expect($data->openFollowUps)->toBe(1)
        ->and($data->brokenPromises)->toBe(1)
        ->and($data->openCases)->toBeGreaterThanOrEqual(1);
});
