<?php

declare(strict_types=1);

use App\Actions\Commission\GenerateCommissionCases;
use App\Actions\Partners\AuthorizePartnerForProjectAction;
use App\Actions\Partners\SetBookingPartnerAttribution;
use App\Enums\CommissionCaseStatus;
use App\Enums\Permission;
use App\Enums\PermissionGroup;
use App\Livewire\Bookings\BookingCommission;
use App\Livewire\Commission\CommissionCaseShow;
use App\Models\Booking;
use App\Models\CommissionCase;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

/** @return array{booking: Booking, case: CommissionCase} */
function generatedCommission(): array
{
    $s = confirmedBookingScenario('5000000');
    $actor = User::factory()->create();
    $partner = Partner::factory()->active()->commission('2')->create();
    app(AuthorizePartnerForProjectAction::class)->handle($partner, $s['booking']->project, $actor);
    app(SetBookingPartnerAttribution::class)->handle($s['booking'], $partner->id, $actor);
    $case = app(GenerateCommissionCases::class)->handle($s['booking']->fresh(), $actor)->first();

    return ['booking' => $s['booking']->fresh(), 'case' => $case];
}

it('forbids the booking commission screen without commission.view', function () {
    ['booking' => $booking] = generatedCommission();
    $user = makeUser(permissions: ['bookings.view']);

    Livewire::actingAs($user)->test(BookingCommission::class, ['booking' => $booking])->assertForbidden();
});

it('lets a commission viewer see the booking commission and case screens', function () {
    ['booking' => $booking, 'case' => $case] = generatedCommission();
    $user = makeUser(permissions: ['bookings.view', 'commission.view']);

    $this->actingAs($user)->get(route('bookings.commission', $booking))->assertOk();
    $this->actingAs($user)->get(route('commission-cases.show', $case))->assertOk()->assertSee($case->case_number);
});

it('stops a viewer without commission.generate from generating', function () {
    ['booking' => $booking] = generatedCommission();
    $user = makeUser(permissions: ['bookings.view', 'commission.view']);

    Livewire::actingAs($user)
        ->test(BookingCommission::class, ['booking' => $booking])
        ->call('generate')
        ->assertForbidden();
});

it('stops recalculate without commission.recalculate', function () {
    ['case' => $case] = generatedCommission();
    $user = makeUser(permissions: ['commission.view']);

    Livewire::actingAs($user)
        ->test(CommissionCaseShow::class, ['case' => $case])
        ->call('recalculate')
        ->assertForbidden();
});

it('cannot recalculate an approved case even with the permission', function () {
    ['case' => $case] = generatedCommission();
    $case->forceFill(['status' => CommissionCaseStatus::Approved])->save();
    $user = makeUser(permissions: ['commission.view', 'commission.recalculate']);

    Livewire::actingAs($user)
        ->test(CommissionCaseShow::class, ['case' => $case->fresh()])
        ->call('recalculate')
        ->assertForbidden();
});

it('files commission-case permissions under the Commission group', function () {
    expect(Permission::CommissionGenerate->group())->toBe(PermissionGroup::Commission);
});
