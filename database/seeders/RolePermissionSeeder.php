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
                'projects.view',
                'plots.view', 'plots.create', 'plots.update',
                'buyers.view', 'buyers.create', 'buyers.update', 'buyers.delete',
                'bookings.view', 'bookings.create', 'bookings.update', 'bookings.cancel',
                'payments.view',
                'reports.view',
            ],

            RoleName::SalesExecutive => [
                'projects.view',
                'plots.view',
                'buyers.view', 'buyers.create', 'buyers.update',
                'bookings.view', 'bookings.create',
                'payments.view',
            ],

            RoleName::Accountant => [
                'payments.view', 'payments.create', 'payments.update', 'payments.approve',
                'buyers.view', 'bookings.view', 'registry.view',
                'reports.view',
            ],

            RoleName::RegistryManager => [
                'registry.view', 'registry.update',
                'plots.view', 'buyers.view', 'bookings.view',
                'reports.view',
            ],

            RoleName::PossessionManager => [
                'possession.view', 'possession.update',
                'plots.view', 'buyers.view', 'bookings.view',
                'reports.view',
            ],

            RoleName::AssociateManager => [
                'associates.view', 'associates.create', 'associates.update',
                'reports.view',
            ],

            RoleName::Viewer => $viewOnly,
        };
    }
}
