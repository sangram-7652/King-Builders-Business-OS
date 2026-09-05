<?php

declare(strict_types=1);

use App\Actions\Bookings\CancelBookingAction;
use App\Actions\Commission\ApproveCommissionCase;
use App\Actions\Commission\RecordCommissionPayout;
use App\Actions\Commission\ReverseCommissionCase;
use App\Actions\Commission\VoidCommissionPayout;
use App\Enums\CommissionCaseEventType;
use App\Enums\CommissionCaseStatus;
use App\Exceptions\DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('reverses an approved case with a zero clawback when nothing was paid', function () {
    ['actor' => $actor, 'case' => $case] = pendingCase();
    app(ApproveCommissionCase::class)->handle($case, $actor);

    app(ReverseCommissionCase::class)->handle($case->fresh(), $actor, 'attribution corrected');

    $fresh = $case->fresh();
    expect($fresh->status)->toBe(CommissionCaseStatus::Reversed)
        ->and((string) $fresh->clawback_amount)->toBe('0.00')
        ->and($fresh->reversal_reason)->toBe('attribution corrected')
        ->and($fresh->events()->where('type', CommissionCaseEventType::Reversed->value)->exists())->toBeTrue();
});

it('sets the clawback to whatever was already paid out', function () {
    ['actor' => $actor, 'case' => $case] = pendingCase();
    app(ApproveCommissionCase::class)->handle($case, $actor);
    app(RecordCommissionPayout::class)->handle($case->fresh(), [
        'amount' => '70000', 'method' => 'cash', 'paid_on' => now()->toDateString(),
    ], $actor);

    app(ReverseCommissionCase::class)->handle($case->fresh(), $actor, 'booking fell through');

    expect((string) $case->fresh()->clawback_amount)->toBe('70000.00')
        ->and($case->fresh()->status)->toBe(CommissionCaseStatus::Reversed);
});

it('freezes payouts on a reversed case', function () {
    ['actor' => $actor, 'case' => $case] = pendingCase();
    app(ApproveCommissionCase::class)->handle($case, $actor);
    $p = app(RecordCommissionPayout::class)->handle($case->fresh(), [
        'amount' => '50000', 'method' => 'cash', 'paid_on' => now()->toDateString(),
    ], $actor);
    app(ReverseCommissionCase::class)->handle($case->fresh(), $actor, 'reversed');

    expect(fn () => app(VoidCommissionPayout::class)->handle($p->fresh(), $actor, 'x'))
        ->toThrow(DomainException::class, 'frozen');

    expect(fn () => app(RecordCommissionPayout::class)->handle($case->fresh(), [
        'amount' => '10', 'method' => 'cash', 'paid_on' => now()->toDateString(),
    ], $actor))->toThrow(DomainException::class);
});

it('auto-reverses an approved unpaid case when the booking is cancelled', function () {
    ['actor' => $actor, 'booking' => $booking, 'case' => $case] = pendingCase();
    app(ApproveCommissionCase::class)->handle($case, $actor);

    app(CancelBookingAction::class)->handle($booking->fresh(), $actor, 'buyer withdrew');

    expect($case->fresh()->status)->toBe(CommissionCaseStatus::Reversed)
        ->and((string) $case->fresh()->clawback_amount)->toBe('0.00');
});

it('leaves a partially-paid case for a human to reverse on booking cancel', function () {
    ['actor' => $actor, 'booking' => $booking, 'case' => $case] = pendingCase();
    app(ApproveCommissionCase::class)->handle($case, $actor);
    app(RecordCommissionPayout::class)->handle($case->fresh(), [
        'amount' => '30000', 'method' => 'cash', 'paid_on' => now()->toDateString(),
    ], $actor);

    app(CancelBookingAction::class)->handle($booking->fresh(), $actor, 'cancelled');

    // Not auto-reversed — money was paid.
    expect($case->fresh()->status)->toBe(CommissionCaseStatus::PartiallyPaid);

    app(ReverseCommissionCase::class)->handle($case->fresh(), $actor, 'booking cancelled');
    expect($case->fresh()->status)->toBe(CommissionCaseStatus::Reversed)
        ->and((string) $case->fresh()->clawback_amount)->toBe('30000.00');
});

it('is idempotent on a repeat reversal', function () {
    ['actor' => $actor, 'case' => $case] = pendingCase();
    app(ApproveCommissionCase::class)->handle($case, $actor);
    app(ReverseCommissionCase::class)->handle($case->fresh(), $actor, 'x');
    app(ReverseCommissionCase::class)->handle($case->fresh(), $actor, 'x');

    expect($case->fresh()->events()->where('type', CommissionCaseEventType::Reversed->value)->count())->toBe(1);
});
