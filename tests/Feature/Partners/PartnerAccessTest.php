<?php

declare(strict_types=1);

use App\Actions\Partners\CreatePartnerAction;
use App\Enums\PartnerStatus;
use App\Enums\Permission;
use App\Enums\PermissionGroup;
use App\Livewire\Partners\PartnerForm;
use App\Livewire\Partners\PartnerShow;
use App\Models\Partner;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\Masters\DocumentRequirementSeeder;
use Database\Seeders\Masters\DocumentTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

function partnerViewer(): User
{
    return makeUser(permissions: ['partners.view']);
}

function partnerManager(): User
{
    return makeUser(permissions: ['partners.view', 'partners.create', 'partners.update', 'partners.approve', 'partners.authorize']);
}

it('redirects a guest away from the partner list', function () {
    $this->get('/partners')->assertRedirect('/login');
});

it('forbids an authenticated user without partners.view', function () {
    $this->actingAs(makeUser())->get('/partners')->assertForbidden();
});

it('allows a user with partners.view to see the list and a partner', function () {
    $partner = Partner::factory()->create();
    $user = partnerViewer();

    $this->actingAs($user)->get('/partners')->assertOk();
    $this->actingAs($user)->get(route('partners.show', $partner))->assertOk();
});

it('404s on an unknown partner id', function () {
    $this->actingAs(partnerViewer())->get('/partners/999999')->assertNotFound();
});

it('renders every partner screen for a partner manager (full HTTP stack)', function () {
    seedRbac();
    (new DocumentTypeSeeder)->run();
    (new DocumentRequirementSeeder)->run();

    $user = makeUser(permissions: [
        'partners.view', 'partners.create', 'partners.update', 'partners.approve', 'partners.authorize', 'documents.view',
    ]);
    $partner = Partner::factory()->active()->create();

    $this->actingAs($user);
    $this->get('/partners')->assertOk();
    $this->get('/partners/create')->assertOk()->assertSee('New promoter');
    $this->get(route('partners.show', $partner))->assertOk()->assertSee($partner->partner_code);
    $this->get(route('partners.edit', $partner))->assertOk()->assertSee('Edit promoter');
    $this->get(route('partners.documents', $partner))->assertOk()->assertSee('KYC');
});

it('stops a view-only user from creating or editing a partner', function () {
    $user = partnerViewer();

    Livewire::actingAs($user)->test(PartnerForm::class)->assertForbidden();

    $partner = Partner::factory()->create();
    Livewire::actingAs($user)->test(PartnerForm::class, ['partner' => $partner])->assertForbidden();
});

it('stops a non-approver from moving a partner to active', function () {
    $partner = Partner::factory()->status(PartnerStatus::Pending)->create();
    // can change status (update) but cannot approve
    $user = makeUser(permissions: ['partners.view', 'partners.update']);

    Livewire::actingAs($user)
        ->test(PartnerShow::class, ['partner' => $partner])
        ->call('changeStatus', PartnerStatus::Active->value);

    expect($partner->fresh()->status)->toBe(PartnerStatus::Pending);
});

it('lets an approver move a partner to active from the screen', function () {
    $partner = Partner::factory()->status(PartnerStatus::Pending)->create();

    Livewire::actingAs(partnerManager())
        ->test(PartnerShow::class, ['partner' => $partner])
        ->call('changeStatus', PartnerStatus::Active->value);

    expect($partner->fresh()->status)->toBe(PartnerStatus::Active);
});

it('stops a user without partners.authorize from authorising a project', function () {
    $partner = Partner::factory()->active()->create();
    $project = Project::factory()->create();
    $user = makeUser(permissions: ['partners.view', 'partners.update']);

    Livewire::actingAs($user)
        ->test(PartnerShow::class, ['partner' => $partner])
        ->set('authorizeProjectId', $project->id)
        ->call('authorizeProject')
        ->assertForbidden();

    expect($partner->fresh()->authorizedProjects()->count())->toBe(0);
});

it('hides the full PAN / bank account until a user with partners.approve reveals it', function () {
    $partner = app(CreatePartnerAction::class)->handle([
        'type' => 'individual', 'name' => 'X', 'company_name' => null, 'contact_person' => null,
        'phone' => '9711111111', 'alternate_phone' => null, 'email' => null, 'address' => null,
        'state_id' => null, 'city_id' => null, 'pincode' => null, 'pan_number' => 'ABCDE1234F',
        'rera_number' => null, 'bank_account_name' => 'X', 'bank_account_number' => '11112222333344',
        'bank_ifsc' => 'ABCD0123456', 'bank_name' => 'Bank', 'notes' => null,
    ], User::factory()->create());

    // A plain viewer cannot toggle sensitive reveal.
    Livewire::actingAs(partnerViewer())
        ->test(PartnerShow::class, ['partner' => $partner])
        ->call('toggleSensitive')
        ->assertForbidden();

    // An approver can, and then sees the raw value.
    Livewire::actingAs(partnerManager())
        ->test(PartnerShow::class, ['partner' => $partner])
        ->call('toggleSensitive')
        ->assertSet('revealSensitive', true)
        ->assertSeeHtml('ABCDE1234F');
});

it('exposes partner permissions under the Channel Partners group', function () {
    expect(Permission::PartnersView->group())->toBe(PermissionGroup::Associates)
        ->and(PermissionGroup::Associates->label())->toBe('Channel Partners');
});
