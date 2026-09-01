<?php

declare(strict_types=1);

use App\Enums\PlotStatus;
use App\Enums\RoleName;
use App\Models\Block;
use App\Models\Buyer;
use App\Models\Plot;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/*
| ---------------------------------------------------------------------------
| Test Case bindings
| ---------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)->in('Unit');

pest()->extend(TestCase::class)
    ->beforeEach(fn () => $this->withoutVite())
    ->in('Feature');

/*
| ---------------------------------------------------------------------------
| RBAC helpers
| ---------------------------------------------------------------------------
*/

function seedRbac(): void
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
}

/**
 * Create a user, optionally with roles and/or explicit permissions.
 *
 * @param  array<int, string>  $roles
 * @param  array<int, string>  $permissions
 */
function makeUser(array $roles = [], array $permissions = [], array $attributes = []): User
{
    $user = User::factory()->create($attributes);

    if ($roles !== []) {
        $user->syncRoles($roles);
    }

    if ($permissions !== []) {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);
    }

    return $user->fresh();
}

function superAdmin(): User
{
    seedRbac();

    return makeUser([RoleName::SuperAdmin->value]);
}

/**
 * A user holding the full masters.* permission set (no other access).
 */
function masterAdmin(): User
{
    return makeUser(permissions: [
        'masters.view', 'masters.create', 'masters.update', 'masters.delete',
    ]);
}

/**
 * A user holding the full projects.* permission set (no other access).
 */
function projectManager(): User
{
    return makeUser(permissions: [
        'projects.view', 'projects.create', 'projects.update',
        'projects.delete', 'projects.activate', 'projects.archive',
    ]);
}

/**
 * A user holding the full plots.* permission set (no other access).
 */
function plotManager(): User
{
    return makeUser(permissions: [
        'plots.view', 'plots.create', 'plots.update', 'plots.delete',
        'plots.hold', 'plots.release', 'plots.activate', 'plots.archive',
        'plots.bulk_create',
    ]);
}

/**
 * Full leads.* + buyers.* including view-all and KYC access.
 */
function leadManager(): User
{
    return makeUser(permissions: [
        'leads.view', 'leads.view_all', 'leads.create', 'leads.update', 'leads.delete',
        'leads.assign', 'leads.convert', 'leads.follow_up',
        'buyers.view', 'buyers.create', 'buyers.update', 'buyers.delete',
        'buyers.archive', 'buyers.documents',
    ]);
}

/**
 * A scoped sales agent — sees only their own leads, no assign, no KYC.
 */
function leadAgent(): User
{
    return makeUser(permissions: [
        'leads.view', 'leads.create', 'leads.update', 'leads.convert', 'leads.follow_up',
        'buyers.view', 'buyers.create', 'buyers.update',
    ]);
}

/**
 * Full bookings.* + pricing.* including confirm, cancel, delete and override.
 */
function bookingManager(): User
{
    return makeUser(permissions: [
        'bookings.view', 'bookings.create', 'bookings.update', 'bookings.confirm',
        'bookings.cancel', 'bookings.delete',
        'pricing.view', 'pricing.manage', 'pricing.override',
        'plots.view', 'buyers.view',
    ]);
}

/**
 * A booking clerk — can build and edit bookings but not confirm, cancel,
 * delete or override pricing.
 */
function bookingClerk(): User
{
    return makeUser(permissions: [
        'bookings.view', 'bookings.create', 'bookings.update',
        'pricing.view',
        'plots.view', 'buyers.view',
    ]);
}

/*
| ---------------------------------------------------------------------------
| Booking scenario builders (M6)
| ---------------------------------------------------------------------------
*/

/**
 * A ready-to-book project → block → AVAILABLE plot plus two active buyers.
 *
 * @return array{actor: User, project: Project, block: Block, plot: Plot, buyerA: Buyer, buyerB: Buyer}
 */
function bookingScenario(array $plotAttributes = []): array
{
    $project = Project::factory()->create();
    $block = Block::factory()->create(['project_id' => $project->id]);
    $plot = Plot::factory()->create(array_merge([
        'project_id' => $project->id,
        'block_id' => $block->id,
        'status' => PlotStatus::Available->value,
        'area' => 1000,
    ], $plotAttributes));

    return [
        'actor' => User::factory()->create(),
        'project' => $project,
        'block' => $block,
        'plot' => $plot,
        'buyerA' => Buyer::factory()->create(['status' => 'active']),
        'buyerB' => Buyer::factory()->create(['status' => 'active']),
    ];
}

/**
 * @param  array<string, mixed>  $s  a bookingScenario()
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function bookingPayload(array $s, array $overrides = []): array
{
    $base = [
        'project_id' => $s['project']->id,
        'block_id' => $s['block']->id,
        'plot_id' => $s['plot']->id,
        'booking_date' => now()->toDateString(),
        'notes' => null,
        'pricing' => [
            'base_area' => '1000',
            'base_rate' => '2000',
            'components' => [
                ['type' => 'tax', 'name' => 'GST', 'calculation_type' => 'percentage', 'rate' => '5'],
            ],
        ],
        'buyers' => [
            ['buyer_id' => $s['buyerA']->id, 'ownership_percentage' => '100', 'is_primary' => true],
        ],
    ];

    $payload = array_merge($base, $overrides);

    if (isset($overrides['pricing'])) {
        $payload['pricing'] = array_merge($base['pricing'], $overrides['pricing']);
    }

    return $payload;
}
