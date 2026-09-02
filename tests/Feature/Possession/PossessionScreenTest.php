<?php

declare(strict_types=1);

use App\Actions\Possession\InitiatePossessionCaseAction;
use App\Actions\Transfer\CompleteTransferAction;
use App\Actions\Transfer\CreateTransferRequestAction;
use App\Actions\Transfer\TransferWorkflowAction;
use App\Enums\Permission;
use App\Enums\RoleName;
use App\Enums\TransferType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    Storage::fake('documents');
});

it('registers the full M10 permission set', function () {
    foreach ([
        Permission::PossessionView, Permission::PossessionCreate, Permission::PossessionSchedule,
        Permission::PossessionInspect, Permission::PossessionComplete, Permission::PossessionClear,
        Permission::TransferView, Permission::TransferCreate, Permission::TransferReview,
        Permission::TransferApprove, Permission::TransferComplete, Permission::OwnershipView,
    ] as $p) {
        expect(Spatie\Permission\Models\Permission::where('name', $p->value)->exists())->toBeTrue();
    }
});

it('grants the Possession Manager role the M10 abilities', function () {
    $user = makeUser([RoleName::PossessionManager->value]);

    expect($user->can('possession.complete'))->toBeTrue()
        ->and($user->can('possession.clear'))->toBeTrue()
        ->and($user->can('transfer.approve'))->toBeTrue()
        ->and($user->can('transfer.complete'))->toBeTrue()
        ->and($user->can('ownership.view'))->toBeTrue();
});

it('renders the possession dashboard for an authorised user', function () {
    $s = possessionReadyScenario();
    app(InitiatePossessionCaseAction::class)->handle($s['booking']->fresh(), possessionOfficer());

    $this->actingAs(possessionOfficer())->get(route('possession.dashboard'))->assertOk()->assertSee('Ready');
});

it('renders the transfer dashboard for an authorised user', function () {
    $this->actingAs(possessionOfficer())->get(route('transfers.dashboard'))->assertOk();
});

it('renders the booking possession + transfer screens', function () {
    $s = possessionReadyScenario();

    $this->actingAs(possessionOfficer())->get(route('possession.booking', $s['booking']))->assertOk();
    $this->actingAs(possessionOfficer())->get(route('transfers.booking', $s['booking']))->assertOk();
});

it('exposes possession + ownership on the plot 360 and buyer 360', function () {
    $s = transferReadyScenario();
    $t = app(CreateTransferRequestAction::class)->handle($s['booking']->fresh(), TransferType::SaleTransfer, ['new_buyer_id' => $s['newBuyer']->id], possessionOfficer());
    $officer = possessionOfficer();
    app(TransferWorkflowAction::class)->submit($t, $officer);
    app(TransferWorkflowAction::class)->startReview($t->fresh(), $officer);
    app(TransferWorkflowAction::class)->approve($t->fresh(), $officer);
    app(CompleteTransferAction::class)->handle($t->fresh(), $officer);

    $plot = $s['booking']->plot;
    $this->actingAs(possessionOfficer())
        ->get(route('plots.show', ['project' => $plot->project_id, 'block' => $plot->block_id, 'plot' => $plot->id]))
        ->assertOk()->assertSee('Ownership history');

    $this->actingAs(possessionOfficer())->get(route('buyers.show', $s['newBuyer']))->assertOk()->assertSee('Transfers');
});
