<?php

declare(strict_types=1);

use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Enums\PaymentStatus;
use App\Services\Collections\CollectionReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function reports(): CollectionReportService
{
    return app(CollectionReportService::class);
}

it('builds the outstanding report (33)', function () {
    $s = overdueCaseScenario('1000000');
    $p = app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => '400000',
    ], $s['actor']);
    app(VerifyPaymentAction::class)->handle($p, PaymentStatus::Success, $s['actor']);

    $rows = reports()->outstanding();

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toHaveKeys(['customer', 'booking', 'plot', 'total', 'paid', 'outstanding'])
        ->and($rows[0]['total'])->toBe('1000000.00')
        ->and($rows[0]['paid'])->toBe('400000.00')
        ->and($rows[0]['outstanding'])->toBe('600000.00');
});

it('builds the overdue report (34)', function () {
    overdueCaseScenario('1000000'); // 2 overdue installments

    $rows = reports()->overdue();

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toHaveKeys(['customer', 'booking', 'installment', 'due_date', 'amount', 'paid', 'outstanding', 'days_overdue'])
        ->and($rows[0]['days_overdue'])->toBeGreaterThan($rows[1]['days_overdue']); // sorted worst-first
});

it('builds the aging report (35)', function () {
    overdueCaseScenario('1000000', [
        ['type' => 'amount', 'value' => '400000', 'due_date' => now()->subDays(20)->toDateString()],
        ['type' => 'amount', 'value' => '600000', 'due_date' => now()->subDays(50)->toDateString()],
    ]);

    $rows = collect(reports()->aging())->keyBy('bucket');

    expect($rows['0-30']['amount'])->toBe('400000.00')
        ->and($rows['0-30']['booking_count'])->toBe(1)
        ->and($rows['0-30']['customer_count'])->toBe(1)
        ->and($rows['31-60']['amount'])->toBe('600000.00');
});

it('builds the collection performance report (36)', function () {
    $s = overdueCaseScenario('1000000');
    $p = app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => '300000',
        'payment_date' => now()->toDateString(),
    ], $s['actor']);
    app(VerifyPaymentAction::class)->handle($p, PaymentStatus::Success, $s['actor']);

    $rows = reports()->performance(now()->subWeek(), now());

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toHaveKeys(['date', 'collector', 'amount', 'booking_count'])
        ->and($rows[0]['amount'])->toBe('300000.00')
        ->and($rows[0]['booking_count'])->toBe(1);
});
