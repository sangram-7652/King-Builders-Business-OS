<?php

declare(strict_types=1);

use App\Actions\Plots\ChangePlotStatus;
use App\Actions\Plots\HoldPlotAction;
use App\Actions\Plots\ReleasePlotHoldAction;
use App\Enums\PlotStatus;
use App\Exceptions\DomainException;
use App\Models\Plot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists the six plot statuses', function () {
    expect(PlotStatus::values())
        ->toBe(['available', 'hold', 'booked', 'sold', 'cancelled', 'transferred']);
});

it('a new plot is AVAILABLE', function () {
    expect(Plot::factory()->create()->status)->toBe(PlotStatus::Available);
});

it('holds an available plot then releases it', function () {
    $user = User::factory()->create();
    $plot = Plot::factory()->available()->create();

    app(HoldPlotAction::class)->handle($plot->id, $user->id, now()->addDay(), 'Walk-in');
    $plot->refresh();
    expect($plot->status)->toBe(PlotStatus::Hold)
        ->and($plot->held_by)->toBe($user->id)
        ->and($plot->hold_reason)->toBe('Walk-in')
        ->and($plot->held_at)->not->toBeNull();

    app(ReleasePlotHoldAction::class)->handle($plot->id);
    $plot->refresh();
    expect($plot->status)->toBe(PlotStatus::Available)
        ->and($plot->held_by)->toBeNull()
        ->and($plot->held_at)->toBeNull()
        ->and($plot->hold_expires_at)->toBeNull()
        ->and($plot->hold_reason)->toBeNull();
});

dataset('valid plot transitions', [
    'hold → booked' => [PlotStatus::Hold, PlotStatus::Booked],
    'booked → sold' => [PlotStatus::Booked, PlotStatus::Sold],
    'booked → cancelled' => [PlotStatus::Booked, PlotStatus::Cancelled],
]);

dataset('invalid plot transitions', [
    'available → booked' => [PlotStatus::Available, PlotStatus::Booked],
    'available → sold' => [PlotStatus::Available, PlotStatus::Sold],
    'hold → sold' => [PlotStatus::Hold, PlotStatus::Sold],
    'sold → available' => [PlotStatus::Sold, PlotStatus::Available],
    'cancelled → booked' => [PlotStatus::Cancelled, PlotStatus::Booked],
    'booked → transferred' => [PlotStatus::Booked, PlotStatus::Transferred],
    'available → transferred' => [PlotStatus::Available, PlotStatus::Transferred],
]);

it('allows a valid generic transition', function (PlotStatus $from, PlotStatus $to) {
    $plot = Plot::factory()->status($from)->create();

    app(ChangePlotStatus::class)->handle($plot, $to);

    expect($plot->fresh()->status)->toBe($to);
})->with('valid plot transitions');

it('rejects an invalid generic transition without changing state', function (PlotStatus $from, PlotStatus $to) {
    $plot = Plot::factory()->status($from)->create();

    expect(fn () => app(ChangePlotStatus::class)->handle($plot, $to))
        ->toThrow(DomainException::class);

    expect($plot->fresh()->status)->toBe($from);
})->with('invalid plot transitions');

it('never reaches TRANSFERRED through the generic action', function () {
    expect(PlotStatus::Available->canTransitionTo(PlotStatus::Transferred))->toBeFalse()
        ->and(PlotStatus::Booked->canTransitionTo(PlotStatus::Transferred))->toBeFalse()
        ->and(PlotStatus::Sold->canTransitionTo(PlotStatus::Transferred))->toBeFalse()
        ->and(PlotStatus::Transferred->isTerminal())->toBeTrue();
});

it('clears hold metadata when a HOLD plot is moved to BOOKED', function () {
    $plot = Plot::factory()->onHold(now()->addDay())->create();

    app(ChangePlotStatus::class)->handle($plot, PlotStatus::Booked);

    $plot->refresh();
    expect($plot->status)->toBe(PlotStatus::Booked)
        ->and($plot->held_at)->toBeNull()
        ->and($plot->hold_expires_at)->toBeNull()
        ->and($plot->hold_reason)->toBeNull()
        ->and($plot->held_by)->toBeNull();
});

it('refuses to hold a plot that is not available', function () {
    $plot = Plot::factory()->status(PlotStatus::Booked)->create();

    expect(fn () => app(HoldPlotAction::class)->handle($plot->id, null))
        ->toThrow(DomainException::class);
});

it('refuses to hold an archived plot', function () {
    $plot = Plot::factory()->available()->inactive()->create();

    expect(fn () => app(HoldPlotAction::class)->handle($plot->id, null))
        ->toThrow(DomainException::class, 'archived');
});

it('refuses to release a plot that is not on hold', function () {
    $plot = Plot::factory()->available()->create();

    expect(fn () => app(ReleasePlotHoldAction::class)->handle($plot->id))
        ->toThrow(DomainException::class);
});
