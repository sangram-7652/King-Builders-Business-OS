<?php

declare(strict_types=1);

use App\Actions\Collections\EnsureCollectionCaseAction;
use App\Actions\Payments\ActivatePaymentPlanAction;
use App\Actions\Payments\CreatePaymentPlanAction;
use App\Enums\PlotStatus;
use App\Enums\RoleName;
use App\Models\Block;
use App\Models\Booking;
use App\Models\BookingBuyer;
use App\Models\Buyer;
use App\Models\CollectionCase;
use App\Models\Masters\PaymentMode;
use App\Models\PaymentPlan;
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

/**
 * Full payments.* + payment_plans.* + receipts.* (finance manager).
 */
function financeManager(): User
{
    return makeUser(permissions: [
        'payment_plans.view', 'payment_plans.create', 'payment_plans.update', 'payment_plans.activate',
        'payments.view', 'payments.create', 'payments.verify', 'payments.allocate', 'payments.reverse',
        'receipts.view', 'receipts.generate',
        'bookings.view', 'buyers.view',
    ]);
}

/**
 * A cashier — records payments and views, but cannot verify, allocate or
 * reverse, and cannot touch payment plans.
 */
function cashier(): User
{
    return makeUser(permissions: [
        'payment_plans.view', 'payments.view', 'payments.create', 'receipts.view',
        'bookings.view',
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

/*
| ---------------------------------------------------------------------------
| Payment scenario builders (M7)
| ---------------------------------------------------------------------------
*/

/**
 * A CONFIRMED booking (final_amount = ₹1,000,000) with a BOOKED plot and one
 * primary buyer, plus an actor. Ready for a payment plan.
 *
 * @return array{actor: User, booking: Booking, buyer: Buyer}
 */
function confirmedBookingScenario(string $finalAmount = '1000000'): array
{
    $project = Project::factory()->create();
    $block = Block::factory()->create(['project_id' => $project->id]);
    $plot = Plot::factory()->create([
        'project_id' => $project->id,
        'block_id' => $block->id,
        'status' => PlotStatus::Booked->value,
    ]);

    $booking = Booking::factory()->confirmed()->forPlot($plot)->create([
        'final_amount' => $finalAmount,
        'base_amount' => $finalAmount,
        'subtotal' => $finalAmount,
    ]);

    $buyer = Buyer::factory()->create(['status' => 'active']);
    BookingBuyer::factory()->create([
        'booking_id' => $booking->id,
        'buyer_id' => $buyer->id,
        'ownership_percentage' => 100,
        'is_primary' => true,
    ]);

    return ['actor' => User::factory()->create(), 'booking' => $booking, 'buyer' => $buyer];
}

/**
 * The CASH payment mode (seeded, or created for the test).
 */
function cashMode(): PaymentMode
{
    return PaymentMode::query()->firstOrCreate(
        ['code' => 'CASH'],
        ['name' => 'Cash', 'requires_reference' => false, 'is_cheque' => false, 'is_active' => true, 'is_system' => true, 'sort_order' => 0],
    );
}

function chequeMode(): PaymentMode
{
    return PaymentMode::query()->firstOrCreate(
        ['code' => 'CHEQUE'],
        ['name' => 'Cheque', 'requires_reference' => true, 'is_cheque' => true, 'is_active' => true, 'is_system' => true, 'sort_order' => 1],
    );
}

/**
 * Create + activate a plan with the given schedule (defaults to 4 × 25%).
 *
 * @param  list<array<string, mixed>>|null  $schedule
 */
function activePlanFor(Booking $booking, User $actor, ?array $schedule = null): PaymentPlan
{
    $schedule ??= [
        ['type' => 'percentage', 'value' => '25', 'due_date' => now()->subMonths(2)->toDateString()],
        ['type' => 'percentage', 'value' => '25', 'due_date' => now()->subMonth()->toDateString()],
        ['type' => 'percentage', 'value' => '25', 'due_date' => now()->addMonth()->toDateString()],
        ['type' => 'percentage', 'value' => '25', 'due_date' => now()->addMonths(2)->toDateString()],
    ];

    $plan = app(CreatePaymentPlanAction::class)->handle($booking, ['schedule' => $schedule], $actor);

    return app(ActivatePaymentPlanAction::class)->handle($plan, $actor);
}

/*
| ---------------------------------------------------------------------------
| Collection helpers (M8)
| ---------------------------------------------------------------------------
*/

/** Full collections.* + promises.* + cheques.* + penalties.* incl. view_all. */
function collectionManager(): User
{
    return makeUser(permissions: [
        'collections.view', 'collections.view_all', 'collections.create', 'collections.update',
        'collections.assign', 'collections.follow_up', 'collections.reports',
        'promises.view', 'promises.create', 'promises.update',
        'cheques.view', 'cheques.update', 'cheques.bounce',
        'penalties.view', 'penalties.assess', 'penalties.approve',
        'bookings.view', 'buyers.view', 'payments.view', 'payment_plans.view',
    ]);
}

/** A scoped collection executive — assigned cases only, no assign, no penalty approval. */
function collectionExecutive(): User
{
    return makeUser(permissions: [
        'collections.view', 'collections.create', 'collections.update', 'collections.follow_up',
        'promises.view', 'promises.create', 'promises.update',
        'cheques.view', 'penalties.view',
        'bookings.view', 'buyers.view',
    ]);
}

/**
 * A confirmed booking with an ACTIVE, OVERDUE plan plus an ensured collection case.
 *
 * @param  list<array<string, mixed>>|null  $schedule
 * @return array{actor: User, booking: Booking, buyer: Buyer, case: CollectionCase}
 */
function overdueCaseScenario(string $finalAmount = '1000000', ?array $schedule = null): array
{
    $s = confirmedBookingScenario($finalAmount);

    activePlanFor($s['booking'], $s['actor'], $schedule ?? [
        ['type' => 'amount', 'value' => '400000', 'due_date' => now()->subDays(75)->toDateString()],
        ['type' => 'amount', 'value' => '300000', 'due_date' => now()->subDays(20)->toDateString()],
        ['type' => 'amount', 'value' => '300000', 'due_date' => now()->addDays(30)->toDateString()],
    ]);

    $case = app(EnsureCollectionCaseAction::class)->handle($s['booking']->fresh(), $s['actor']);

    return $s + ['case' => $case];
}
