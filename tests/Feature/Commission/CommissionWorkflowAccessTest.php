<?php

declare(strict_types=1);

use App\Actions\Commission\ApproveCommissionCase;
use App\Livewire\Commission\CommissionCaseShow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('renders the commission worklist for a commission viewer', function () {
    ['case' => $case] = pendingCase();
    $user = makeUser(permissions: ['commission.view']);

    $this->actingAs($user)->get(route('commissions.index'))->assertOk()->assertSee($case->case_number);
});

it('forbids the worklist without commission.view', function () {
    $this->actingAs(makeUser())->get(route('commissions.index'))->assertForbidden();
});

it('stops a viewer without commission.approve from approving', function () {
    ['case' => $case] = pendingCase();
    $user = makeUser(permissions: ['commission.view']);

    Livewire::actingAs($user)
        ->test(CommissionCaseShow::class, ['case' => $case])
        ->call('approve')
        ->assertForbidden();

    expect($case->fresh()->status->value)->toBe('pending_review');
});

it('lets an approver approve from the case screen', function () {
    ['case' => $case] = pendingCase();
    $user = makeUser(permissions: ['commission.view', 'commission.approve']);

    Livewire::actingAs($user)
        ->test(CommissionCaseShow::class, ['case' => $case])
        ->call('approve')
        ->assertHasNoErrors();

    expect($case->fresh()->status->value)->toBe('approved');
});

it('stops a non-payout user from recording a payout', function () {
    ['actor' => $actor, 'case' => $case] = pendingCase();
    app(ApproveCommissionCase::class)->handle($case, $actor);
    $user = makeUser(permissions: ['commission.view', 'commission.approve']); // no payout

    Livewire::actingAs($user)
        ->test(CommissionCaseShow::class, ['case' => $case->fresh()])
        ->call('openPayout')
        ->assertForbidden();
});

it('stops a non-reverse user from reversing', function () {
    ['actor' => $actor, 'case' => $case] = pendingCase();
    app(ApproveCommissionCase::class)->handle($case, $actor);
    $user = makeUser(permissions: ['commission.view', 'commission.approve']);

    Livewire::actingAs($user)
        ->test(CommissionCaseShow::class, ['case' => $case->fresh()])
        ->set('pendingAction', 'reverse')
        ->set('reasonInput', 'because')
        ->call('submitReason')
        ->assertForbidden();
});

it('records a payout end to end for an accountant-style user', function () {
    ['actor' => $actor, 'case' => $case] = pendingCase();
    app(ApproveCommissionCase::class)->handle($case, $actor);
    $user = makeUser(permissions: ['commission.view', 'commission.payout']);

    Livewire::actingAs($user)
        ->test(CommissionCaseShow::class, ['case' => $case->fresh()])
        ->call('openPayout')
        ->set('payoutAmount', '100000')
        ->set('payoutMethod', 'bank_transfer')
        ->set('payoutDate', now()->toDateString())
        ->call('recordPayout')
        ->assertHasNoErrors();

    expect($case->fresh()->status->value)->toBe('paid');
});
