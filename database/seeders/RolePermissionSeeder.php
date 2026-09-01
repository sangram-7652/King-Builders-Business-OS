<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Enums\RoleName;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotent: creates every permission and system role, then (re)syncs the
 * default permission set for each system role. Safe to run repeatedly.
 *
 * Custom roles created through the UI are never touched here.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function (): void {
            foreach (PermissionEnum::cases() as $permission) {
                Permission::findOrCreate($permission->value, 'web');
            }

            foreach (RoleName::cases() as $roleName) {
                $role = Role::findOrCreate($roleName->value, 'web');
                $role->syncPermissions($this->permissionsFor($roleName));
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return list<string>
     */
    private function permissionsFor(RoleName $role): array
    {
        $all = PermissionEnum::values();

        $viewOnly = array_values(array_filter(
            $all,
            static fn (string $p): bool => str_ends_with($p, '.view'),
        ));

        return match ($role) {
            RoleName::SuperAdmin, RoleName::Admin => $all,

            RoleName::SalesManager => [
                'projects.view', 'projects.create', 'projects.update', 'projects.activate', 'projects.archive',
                'plots.view', 'plots.create', 'plots.update', 'plots.delete',
                'plots.hold', 'plots.release', 'plots.activate', 'plots.archive', 'plots.bulk_create',
                'leads.view', 'leads.view_all', 'leads.create', 'leads.update', 'leads.delete',
                'leads.assign', 'leads.convert', 'leads.follow_up',
                'buyers.view', 'buyers.create', 'buyers.update', 'buyers.delete',
                'buyers.archive', 'buyers.documents',
                'bookings.view', 'bookings.create', 'bookings.update', 'bookings.confirm',
                'bookings.cancel', 'bookings.delete',
                'pricing.view', 'pricing.manage', 'pricing.override',
                'payment_plans.view', 'payment_plans.create', 'payment_plans.update', 'payment_plans.activate',
                'payments.view', 'payments.create',
                'receipts.view',
                'collections.view', 'collections.view_all', 'collections.assign', 'collections.reports',
                'promises.view', 'cheques.view', 'penalties.view',
                'reports.view',
                'masters.view',
            ],

            RoleName::SalesExecutive => [
                'projects.view',
                'plots.view', 'plots.hold', 'plots.release',
                'leads.view', 'leads.create', 'leads.update', 'leads.convert', 'leads.follow_up',
                'buyers.view', 'buyers.create', 'buyers.update',
                'bookings.view', 'bookings.create', 'bookings.update',
                'pricing.view',
                'payment_plans.view', 'payments.view', 'receipts.view',
                'masters.view',
            ],

            RoleName::Accountant => [
                'payment_plans.view', 'payment_plans.create', 'payment_plans.update', 'payment_plans.activate',
                'payments.view', 'payments.create', 'payments.verify', 'payments.allocate', 'payments.reverse',
                'receipts.view', 'receipts.generate',
                'collections.view', 'collections.view_all', 'collections.reports',
                'promises.view', 'cheques.view', 'cheques.update', 'cheques.bounce',
                'penalties.view', 'penalties.assess', 'penalties.approve',
                'buyers.view', 'buyers.documents', 'bookings.view', 'registry.view',
                'pricing.view', 'pricing.manage',
                'reports.view',
                'masters.view', 'masters.create', 'masters.update',
            ],

            RoleName::CollectionManager => [
                'collections.view', 'collections.view_all', 'collections.create', 'collections.update',
                'collections.assign', 'collections.follow_up', 'collections.reports',
                'promises.view', 'promises.create', 'promises.update',
                'cheques.view', 'cheques.update', 'cheques.bounce',
                'penalties.view', 'penalties.assess', 'penalties.approve',
                'bookings.view', 'buyers.view', 'payments.view', 'payment_plans.view', 'receipts.view',
                'reports.view', 'masters.view',
            ],

            RoleName::CollectionExecutive => [
                'collections.view', 'collections.create', 'collections.update', 'collections.follow_up',
                'promises.view', 'promises.create', 'promises.update',
                'cheques.view',
                'penalties.view',
                'bookings.view', 'buyers.view', 'payments.view', 'payment_plans.view', 'receipts.view',
                'masters.view',
            ],

            RoleName::RegistryManager => [
                'registry.view', 'registry.update',
                'plots.view', 'buyers.view', 'bookings.view',
                'reports.view',
                'masters.view',
            ],

            RoleName::PossessionManager => [
                'possession.view', 'possession.update',
                'plots.view', 'buyers.view', 'bookings.view',
                'reports.view',
                'masters.view',
            ],

            RoleName::AssociateManager => [
                'associates.view', 'associates.create', 'associates.update',
                'reports.view',
                'masters.view',
            ],

            RoleName::Viewer => [...$viewOnly, 'leads.view_all'],
        };
    }
}
