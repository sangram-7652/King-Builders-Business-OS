<?php

declare(strict_types=1);

use App\Enums\AgingBucket;
use App\Models\Booking;
use App\Services\Collections\AgingCalculator;
use App\Services\Reports\CollectionAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

/**
 * F-M8-1 / F-TEST-L — the operational aging path (AgingCalculator, via
 * AgingBucket::fromDaysOverdue) and the reporting path (CollectionAnalytics)
 * derive their windows from the single AgingBucket::dayBounds() source and must
 * agree exactly at every boundary: 0-30, 31-60, 61-90, 91-180, 180+.
 */
it('places each edge day in the right bucket (fromDaysOverdue)', function () {
    expect(AgingBucket::fromDaysOverdue(0))->toBeNull()
        ->and(AgingBucket::fromDaysOverdue(1))->toBe(AgingBucket::Days0To30)
        ->and(AgingBucket::fromDaysOverdue(30))->toBe(AgingBucket::Days0To30)
        ->and(AgingBucket::fromDaysOverdue(31))->toBe(AgingBucket::Days31To60)
        ->and(AgingBucket::fromDaysOverdue(60))->toBe(AgingBucket::Days31To60)
        ->and(AgingBucket::fromDaysOverdue(61))->toBe(AgingBucket::Days61To90)
        ->and(AgingBucket::fromDaysOverdue(90))->toBe(AgingBucket::Days61To90)
        ->and(AgingBucket::fromDaysOverdue(91))->toBe(AgingBucket::Days91To180)
        ->and(AgingBucket::fromDaysOverdue(180))->toBe(AgingBucket::Days91To180)
        ->and(AgingBucket::fromDaysOverdue(181))->toBe(AgingBucket::Days180Plus)
        ->and(AgingBucket::fromDaysOverdue(5000))->toBe(AgingBucket::Days180Plus);
});

it('exposes 30/60/90/180 as the shared thresholds exactly once', function () {
    expect(AgingBucket::thresholds())->toBe([30, 60, 90, 180]);
});

it('the report ageing SQL matches the operational calculator at every boundary', function () {
    // One overdue installment per edge day, each on its own booking.
    $edges = [1, 30, 31, 60, 61, 90, 91, 180, 181, 400];

    foreach ($edges as $days) {
        $s = confirmedBookingScenario('1000000');
        activePlanFor($s['booking'], $s['actor'], [
            ['type' => 'amount', 'value' => '1000000', 'due_date' => now()->subDays($days)->toDateString()],
        ]);
    }

    $filters = misFilter(['from' => now()->subYears(3)->toDateString(), 'to' => now()->addYear()->toDateString()]);
    $reportAgeing = collect(app(CollectionAnalytics::class)->ageing($filters))->keyBy('bucket');

    // Operational: walk every confirmed booking through AgingCalculator.
    $aging = app(AgingCalculator::class);
    $operational = array_fill_keys(AgingBucket::values(), 0.0);
    Booking::query()->where('status', 'confirmed')->get()->each(function (Booking $b) use ($aging, &$operational): void {
        foreach ($aging->agingForBooking($b) as $bucket => $money) {
            $operational[$bucket] += (float) $money->store();
        }
    });

    foreach (AgingBucket::cases() as $bucket) {
        expect(round((float) ($reportAgeing[$bucket->value]['outstanding'] ?? 0), 2))
            ->toBe(round($operational[$bucket->value], 2), "bucket {$bucket->value}");
    }
});
