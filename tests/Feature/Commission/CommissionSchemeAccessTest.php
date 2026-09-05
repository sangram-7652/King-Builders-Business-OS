<?php

declare(strict_types=1);

use App\Actions\Commission\PublishCommissionScheme;
use App\Enums\Permission;
use App\Enums\PermissionGroup;
use App\Livewire\Commission\CommissionSchemeForm;
use App\Livewire\Commission\CommissionSchemeShow;
use App\Models\CommissionScheme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

function schemeManager(): User
{
    return makeUser(permissions: ['commission_schemes.view', 'commission_schemes.manage', 'commission_schemes.publish']);
}

it('redirects a guest away from the scheme list', function () {
    $this->get('/commissions/schemes')->assertRedirect('/login');
});

it('forbids a user without commission_schemes.view', function () {
    $this->actingAs(makeUser())->get('/commissions/schemes')->assertForbidden();
});

it('renders the scheme screens for a scheme manager', function () {
    $user = schemeManager();
    $scheme = CommissionScheme::factory()->withPercentageRule()->create();

    $this->actingAs($user);
    $this->get('/commissions/schemes')->assertOk();
    $this->get('/commissions/schemes/create')->assertOk()->assertSee('New commission scheme');
    $this->get(route('commission-schemes.show', $scheme))->assertOk()->assertSee($scheme->code);
    $this->get(route('commission-schemes.edit', $scheme))->assertOk()->assertSee('Edit scheme');
});

it('stops a view-only user from creating or editing a scheme', function () {
    $user = makeUser(permissions: ['commission_schemes.view']);

    Livewire::actingAs($user)->test(CommissionSchemeForm::class)->assertForbidden();

    $scheme = CommissionScheme::factory()->create();
    Livewire::actingAs($user)->test(CommissionSchemeForm::class, ['scheme' => $scheme])->assertForbidden();
});

it('stops a manager without publish rights from publishing', function () {
    $user = makeUser(permissions: ['commission_schemes.view', 'commission_schemes.manage']);
    $scheme = CommissionScheme::factory()->withPercentageRule()->create();

    Livewire::actingAs($user)
        ->test(CommissionSchemeShow::class, ['scheme' => $scheme])
        ->call('publish')
        ->assertForbidden();

    expect($scheme->fresh()->isDraft())->toBeTrue();
});

it('cannot open the edit form for a published scheme', function () {
    $scheme = CommissionScheme::factory()->withPercentageRule()->create();
    app(PublishCommissionScheme::class)->handle($scheme, User::factory()->create());

    Livewire::actingAs(schemeManager())
        ->test(CommissionSchemeForm::class, ['scheme' => $scheme->fresh()])
        ->assertForbidden();
});

it('files commission permissions under the Commission group', function () {
    expect(Permission::CommissionSchemesView->group())->toBe(PermissionGroup::Commission)
        ->and(PermissionGroup::Commission->label())->toBe('Commission');
});
