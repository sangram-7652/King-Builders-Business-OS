<?php

declare(strict_types=1);

use App\Actions\Commission\ApproveCommissionCase;
use App\Actions\Commission\CancelCommissionCase;
use App\Actions\Commission\HoldCommissionCase;
use App\Actions\Commission\RecalculateCommissionCase;
use App\Actions\Commission\ResumeCommissionCase;
use App\Enums\CommissionCaseEventType;
use App\Enums\CommissionCaseStatus;
use App\Exceptions\DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

// pendingCase() lives in tests/Pest.php so every test process has it (it is
// shared with the payout / reversal / access tests and must not depend on this
// file being loaded first — e.g. under --parallel).

it('approves a pending case and stamps the approver', function () {
    ['actor' => $actor, 'case' => $case] = pendingCase();

    app(ApproveCommissionCase::class)->handle($case, $actor);

    $fresh = $case->fresh();
    expect($fresh->status)->toBe(CommissionCaseStatus::Approved)
        ->and($fresh->approved_by)->toBe($actor->id)
        ->and($fresh->approved_at)->not->toBeNull()
        ->and($fresh->events()->where('type', CommissionCaseEventType::Approved->value)->exists())->toBeTrue();
});

it('holds and resumes a case', function () {
    ['actor' => $actor, 'case' => $case] = pendingCase();

    app(HoldCommissionCase::class)->handle($case, $actor, 'awaiting KYC');
    expect($case->fresh()->status)->toBe(CommissionCaseStatus::OnHold)
        ->and($case->fresh()->hold_reason)->toBe('awaiting KYC');

    app(ResumeCommissionCase::class)->handle($case->fresh(), $actor);
    expect($case->fresh()->status)->toBe(CommissionCaseStatus::PendingReview)
        ->and($case->fresh()->hold_reason)->toBeNull();
});

it('holds an approved case too', function () {
    ['actor' => $actor, 'case' => $case] = pendingCase();
    app(ApproveCommissionCase::class)->handle($case, $actor);

    app(HoldCommissionCase::class)->handle($case->fresh(), $actor, 'dispute');

    expect($case->fresh()->status)->toBe(CommissionCaseStatus::OnHold);
});

it('requires a reason to hold or cancel', function () {
    ['actor' => $actor, 'case' => $case] = pendingCase();

    expect(fn () => app(HoldCommissionCase::class)->handle($case, $actor, '  '))
        ->toThrow(DomainException::class, 'reason');
    expect(fn () => app(CancelCommissionCase::class)->handle($case, $actor, ''))
        ->toThrow(DomainException::class, 'reason');
});

it('cancels a pending case but never an approved one', function () {
    ['actor' => $actor, 'case' => $case] = pendingCase();

    $approved = pendingCase();
    app(ApproveCommissionCase::class)->handle($approved['case'], $approved['actor']);

    app(CancelCommissionCase::class)->handle($case, $actor, 'duplicate');
    expect($case->fresh()->status)->toBe(CommissionCaseStatus::Cancelled);

    expect(fn () => app(CancelCommissionCase::class)->handle($approved['case']->fresh(), $approved['actor'], 'x'))
        ->toThrow(DomainException::class, 'reverse it instead');
});

it('cannot approve a cancelled case', function () {
    ['actor' => $actor, 'case' => $case] = pendingCase();
    app(CancelCommissionCase::class)->handle($case, $actor, 'nope');

    expect(fn () => app(ApproveCommissionCase::class)->handle($case->fresh(), $actor))
        ->toThrow(DomainException::class);
});

it('blocks recalculation once approved', function () {
    ['actor' => $actor, 'case' => $case] = pendingCase();
    app(ApproveCommissionCase::class)->handle($case, $actor);

    expect(fn () => app(RecalculateCommissionCase::class)->handle($case->fresh(), $actor))
        ->toThrow(DomainException::class);
});

it('is idempotent on a repeat approval', function () {
    ['actor' => $actor, 'case' => $case] = pendingCase();
    app(ApproveCommissionCase::class)->handle($case, $actor);
    app(ApproveCommissionCase::class)->handle($case->fresh(), $actor);

    expect($case->fresh()->events()->where('type', CommissionCaseEventType::Approved->value)->count())->toBe(1);
});
