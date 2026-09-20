<?php

declare(strict_types=1);

use App\Actions\Documents\UploadDocumentAction;
use App\Actions\Registry\InitiateRegistryCaseAction;
use App\Enums\Permission;
use App\Enums\RoleName;
use App\Livewire\Buyers\BuyerDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    seedDocumentMasters();
    Storage::fake('documents');
});

/*
| PERMISSIONS / RBAC
*/

it('registers the full M9 permission set', function () {
    foreach ([
        Permission::DocumentsView, Permission::DocumentsUpload, Permission::DocumentsVerify,
        Permission::DocumentsReject, Permission::DocumentsDownload, Permission::DocumentsDelete,
        Permission::AgreementsView, Permission::AgreementsCreate, Permission::AgreementsUpdate, Permission::AgreementsApprove,
        Permission::RegistryView, Permission::RegistryCreate, Permission::RegistryUpdate,
        Permission::RegistrySchedule, Permission::RegistryComplete,
        Permission::RegistryExpensesView, Permission::RegistryExpensesCreate, Permission::RegistryExpensesApprove,
        Permission::HandoverView, Permission::HandoverCreate, Permission::HandoverComplete,
    ] as $permission) {
        expect(Spatie\Permission\Models\Permission::where('name', $permission->value)->exists())->toBeTrue();
    }
});

it('grants the Registry Manager role the M9 abilities', function () {
    $user = makeUser([RoleName::RegistryManager->value]);

    expect($user->can('documents.verify'))->toBeTrue()
        ->and($user->can('agreements.approve'))->toBeTrue()
        ->and($user->can('registry.complete'))->toBeTrue()
        ->and($user->can('handover.complete'))->toBeTrue()
        ->and($user->can('registry_expenses.approve'))->toBeTrue();
});

it('keeps M9 write abilities away from a read-only viewer', function () {
    $viewer = makeUser([RoleName::Viewer->value]);

    expect($viewer->can('documents.verify'))->toBeFalse()
        ->and($viewer->can('registry.complete'))->toBeFalse()
        ->and($viewer->can('handover.complete'))->toBeFalse();
});

/*
| SCREENS
*/

it('renders the document dashboard for an authorised user', function () {
    $s = confirmedBookingScenario();
    app(UploadDocumentAction::class)->handle($s['buyer'], docType('AADHAAR'), fakeDocument(), $s['actor']);

    $this->actingAs(registryOfficer())->get(route('documents.dashboard'))->assertOk()->assertSee('Pending');
});

it('renders the registry dashboard for an authorised user', function () {
    $s = registryReadyScenario();
    app(InitiateRegistryCaseAction::class)->handle($s['booking']->fresh(), registryOfficer());

    $this->actingAs(registryOfficer())->get(route('registry.dashboard'))->assertOk();
});

it('renders the buyer + booking document screens', function () {
    $s = confirmedBookingScenario();

    $this->actingAs(registryOfficer())->get(route('buyers.documents', $s['buyer']))->assertOk();
    $this->actingAs(registryOfficer())->get(route('documents.booking', $s['booking']))->assertOk();
    $this->actingAs(registryOfficer())->get(route('registry.booking', $s['booking']))->assertOk();
});

it('blocks the registry dashboard without registry.view', function () {
    $this->actingAs(makeUser(permissions: ['documents.view']))
        ->get(route('registry.dashboard'))->assertForbidden();
});

it('uploads a document through the buyer Livewire screen', function () {
    $s = confirmedBookingScenario();

    Livewire\Livewire::actingAs(registryOfficer())
        ->test(BuyerDocuments::class, ['buyer' => $s['buyer']])
        ->set('files.'.docType('AADHAAR')->id, fakeDocument('aadhaar.pdf'))
        ->assertHasNoErrors();

    expect($s['buyer']->documents()->count())->toBe(1);
});
