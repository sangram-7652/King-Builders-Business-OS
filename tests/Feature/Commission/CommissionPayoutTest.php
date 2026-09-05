<?php

declare(strict_types=1);

use App\Actions\Commission\ApproveCommissionCase;
use App\Actions\Commission\RecordCommissionPayout;
use App\Actions\Commission\VoidCommissionPayout;
use App\Enums\CommissionCaseEventType;
use App\Enums\CommissionCaseStatus;
use App\Exceptions\DomainException;
use App\Models\CommissionPayout;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

function approvedCase(): array
{
    $w = pendingCase(); // from CommissionWorkflowTest — ₹100000
    app(ApproveCommissionCase::class)->handle($w['case'], $w['actor']);

    return ['actor' => $w['actor'], 'case' => $w['case']->fresh()];
}

function payout(array $case, string $amount, ?string $date = null): CommissionPayout
{
    return app(RecordCommissionPayout::class)->handle($case['case']->fresh(), [
        'amount' => $amount,
        'method' => 'bank_transfer',
        'paid_on' => $date ?? now()->toDateString(),
    ], $case['actor']);
}

it('records a partial payout and moves the case to partially paid', function () {
    $c = approvedCase();

    payout($c, '40000');

    $fresh = $c['case']->fresh();
    expect((string) $fresh->paid_amount)->toBe('40000.00')
        ->and($fresh->status)->toBe(CommissionCaseStatus::PartiallyPaid)
        ->and((string) $fresh->outstandingAmount())->toBe('60000.00');
});

it('flips the case to paid once fully settled', function () {
    $c = approvedCase();

    payout($c, '60000');
    payout($c, '40000');

    $fresh = $c['case']->fresh();
    expect((string) $fresh->paid_amount)->toBe('100000.00')
        ->and($fresh->status)->toBe(CommissionCaseStatus::Paid)
        ->and($fresh->events()->where('type', CommissionCaseEventType::FullyPaid->value)->exists())->toBeTrue();
});

it('rejects a payout that would exceed the commission', function () {
    $c = approvedCase();
    payout($c, '90000');

    expect(fn () => payout($c, '20000'))
        ->toThrow(DomainException::class, 'still payable');
});

it('rejects a non-positive payout', function () {
    $c = approvedCase();

    expect(fn () => payout($c, '0'))->toThrow(DomainException::class, 'greater than zero');
});

it('cannot record a payout against a pending or paid case', function () {
    ['actor' => $actor, 'case' => $case] = pendingCase();
    expect(fn () => app(RecordCommissionPayout::class)->handle($case, [
        'amount' => '1000', 'method' => 'cash', 'paid_on' => now()->toDateString(),
    ], $actor))->toThrow(DomainException::class);

    $c = approvedCase();
    payout($c, '100000');
    expect(fn () => payout($c, '1'))->toThrow(DomainException::class);
});

it('voids a payout and steps the case status back', function () {
    $c = approvedCase();
    $p1 = payout($c, '60000');
    payout($c, '40000');
    expect($c['case']->fresh()->status)->toBe(CommissionCaseStatus::Paid);

    app(VoidCommissionPayout::class)->handle($p1->fresh(), $c['actor'], 'sent to the wrong account');

    $fresh = $c['case']->fresh();
    expect($p1->fresh()->isVoided())->toBeTrue()
        ->and((string) $fresh->paid_amount)->toBe('40000.00')
        ->and($fresh->status)->toBe(CommissionCaseStatus::PartiallyPaid)
        ->and($fresh->events()->where('type', CommissionCaseEventType::PayoutVoided->value)->exists())->toBeTrue();
});

it('voiding the only payout returns the case to approved', function () {
    $c = approvedCase();
    $p = payout($c, '30000');

    app(VoidCommissionPayout::class)->handle($p->fresh(), $c['actor'], 'error');

    expect((string) $c['case']->fresh()->paid_amount)->toBe('0.00')
        ->and($c['case']->fresh()->status)->toBe(CommissionCaseStatus::Approved);
});

it('lets a fresh payout use the freed amount after a void', function () {
    $c = approvedCase();
    $p = payout($c, '100000');
    app(VoidCommissionPayout::class)->handle($p->fresh(), $c['actor'], 'redo');

    payout($c, '100000'); // would fail if the voided one still counted

    expect($c['case']->fresh()->status)->toBe(CommissionCaseStatus::Paid);
});
