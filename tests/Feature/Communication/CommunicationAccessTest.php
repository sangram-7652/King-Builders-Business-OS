<?php

declare(strict_types=1);

use App\Actions\Communication\RetryCommunication;
use App\Enums\CommunicationStatus;
use App\Enums\Permission;
use App\Enums\PermissionGroup;
use App\Models\Communication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('files communications permissions under the Communications group', function () {
    expect(Permission::CommunicationsView->group())->toBe(PermissionGroup::Communication)
        ->and(PermissionGroup::Communication->label())->toBe('Communications');
});

it('gates viewing on communications.view and retry on communications.manage', function () {
    $comm = Communication::factory()->failed()->create();

    $viewer = makeUser(permissions: ['communications.view']);
    $manager = makeUser(permissions: ['communications.view', 'communications.manage']);
    $nobody = makeUser();

    expect($nobody->can('viewAny', Communication::class))->toBeFalse()
        ->and($viewer->can('viewAny', Communication::class))->toBeTrue()
        ->and($viewer->can('retry', $comm))->toBeFalse()
        ->and($manager->can('retry', $comm))->toBeTrue();
});

it('only allows retry from the FAILED state via the policy', function () {
    $manager = makeUser(permissions: ['communications.view', 'communications.manage']);

    expect($manager->can('retry', Communication::factory()->status(CommunicationStatus::Sent)->create()))->toBeFalse()
        ->and($manager->can('retry', Communication::factory()->failed()->create()))->toBeTrue()
        ->and($manager->can('cancel', Communication::factory()->status(CommunicationStatus::Queued)->create()))->toBeTrue();
});

it('grants Sales Manager the communications permissions via the seeder', function () {
    expect(Role::findByName('Sales Manager', 'web')->hasPermissionTo('communications.view'))->toBeTrue();
});

it('the manual retry action requires the caller to pass a user (audited)', function () {
    $comm = Communication::factory()->failed()->create();

    app(RetryCommunication::class)->handle($comm, User::factory()->create());

    expect($comm->fresh()->status)->not->toBe(CommunicationStatus::Failed);
});
