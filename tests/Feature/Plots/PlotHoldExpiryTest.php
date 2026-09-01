<?php

declare(strict_types=1);

use App\Actions\Plots\ExpirePlotHoldsAction;
use App\Enums\PlotStatus;
use App\Jobs\ExpirePlotHoldsJob;
use App\Models\Plot;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns a lapsed hold to AVAILABLE and clears its metadata', function () {
    $plot = Plot::factory()->onHold(now()->subHour())->create(['held_by' => null]);

    $released = app(ExpirePlotHoldsAction::class)->handle();

    expect($released)->toBe(1)
        ->and($plot->fresh()->status)->toBe(PlotStatus::Available)
        ->and($plot->fresh()->hold_expires_at)->toBeNull()
        ->and($plot->fresh()->hold_reason)->toBeNull();
});

it('leaves an unexpired or open-ended hold alone', function () {
    $future = Plot::factory()->onHold(now()->addDay())->create();
    $openEnded = Plot::factory()->onHold(null)->create();

    expect(app(ExpirePlotHoldsAction::class)->handle())->toBe(0)
        ->and($future->fresh()->status)->toBe(PlotStatus::Hold)
        ->and($openEnded->fresh()->status)->toBe(PlotStatus::Hold);
});

it('is idempotent — a second run does nothing', function () {
    Plot::factory()->count(3)->onHold(now()->subHour())->create(['held_by' => null]);

    expect(app(ExpirePlotHoldsAction::class)->handle())->toBe(3)
        ->and(app(ExpirePlotHoldsAction::class)->handle())->toBe(0)
        ->and(Plot::where('status', PlotStatus::Available->value)->count())->toBe(3);
});

it('does not expire a plot that has meanwhile moved to another valid state', function () {
    // Hold looks expired, but it has already been booked.
    $plot = Plot::factory()->status(PlotStatus::Booked)->create([
        'hold_expires_at' => now()->subHour(),
    ]);

    expect(app(ExpirePlotHoldsAction::class)->handle())->toBe(0)
        ->and($plot->fresh()->status)->toBe(PlotStatus::Booked);
});

it('is dispatched by the scheduled job', function () {
    $plot = Plot::factory()->onHold(now()->subHour())->create(['held_by' => null]);

    (new ExpirePlotHoldsJob)->handle(app(ExpirePlotHoldsAction::class));

    expect($plot->fresh()->status)->toBe(PlotStatus::Available);
});

it('registers the hold-expiry task on the schedule', function () {
    $events = collect(app(Schedule::class)->events());

    expect($events->contains(fn ($e) => $e->description === 'expire-plot-holds'))->toBeTrue();
});

it('exposes a manual artisan command', function () {
    Plot::factory()->onHold(now()->subHour())->create(['held_by' => null]);

    $this->artisan('plots:expire-holds')
        ->expectsOutputToContain('Released 1 lapsed hold')
        ->assertSuccessful();
});
